<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentSplit;
use App\Modules\Payments\Models\SplitRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Split / repasse médico.
 *
 * Para cada recebimento de conta com médico, a regra mais específica (tipo de
 * atendimento + pagador > tipo de atendimento > pagador > geral) define a parte do
 * médico. Se a cobrança foi feita no ASAAS com a carteira do médico, o próprio
 * gateway separa o dinheiro (split NATIVO, já liquidado). Caso contrário (dinheiro,
 * maquininha, Cielo), a parte vira REPASSE INTERNO pendente, pago no fechamento.
 * Estorno do recebimento desfaz o split (ou gera devolução, se já foi repassado).
 */
class SplitService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function ruleFor(Receivable $r): ?SplitRule
    {
        if (! $r->doctor_id) {
            return null;
        }

        $serviceId = $r->appointment_id ? DB::table('appointments')->where('id', $r->appointment_id)->value('service_id') : null;

        return SplitRule::query()->where('doctor_id', $r->doctor_id)->where('is_active', true)->get()
            ->filter(fn (SplitRule $rule) => ($rule->doctor_service_id === null || $rule->doctor_service_id === $serviceId)
                && ($rule->payer_type === null || $rule->payer_type === $r->payer_type))
            ->sortByDesc(fn (SplitRule $rule) => [($rule->doctor_service_id ? 2 : 0) + ($rule->payer_type ? 1 : 0), $rule->created_at?->timestamp])
            ->first();
    }

    /** Split nativo para enviar ao gateway (ASAAS): só com regra e carteira do médico. */
    public function nativePayload(Receivable $r, PaymentGateway $gateway, int $amount): ?array
    {
        if ($gateway->provider !== 'asaas' || ! ($rule = $this->ruleFor($r))) {
            return null;
        }

        $wallet = DB::table('doctors')->where('id', $r->doctor_id)->value('asaas_wallet_id');
        if (! $wallet) {
            return null;
        }

        return [
            'doctor_id' => $r->doctor_id, 'rule_id' => $rule->id, 'wallet_id' => $wallet,
            'type' => $rule->type, 'value' => $rule->value, 'expected_cents' => $rule->shareOf($amount),
        ];
    }

    public function applyForReceipt(FinancialTransaction $txn, ?PaymentCharge $charge = null): ?PaymentSplit
    {
        if ($txn->kind !== 'receipt' || ! $txn->receivable_id) {
            return null;
        }

        $r = Receivable::query()->find($txn->receivable_id);
        if (! $r || ! ($rule = $this->ruleFor($r))) {
            return null;
        }

        $native = $charge?->split_snapshot && ($charge->split_snapshot['doctor_id'] ?? null) === $r->doctor_id;
        // Split nativo do ASAAS incide sobre o valor líquido (após a tarifa do gateway).
        $base = $native && $charge->net_cents ? $charge->net_cents : $txn->amount_cents;
        $amount = $rule->shareOf($base);

        if ($amount <= 0) {
            return null;
        }

        return PaymentSplit::create([
            'doctor_id' => $r->doctor_id, 'receivable_id' => $r->id, 'transaction_id' => $txn->id, 'charge_id' => $charge?->id,
            'split_rule_id' => $rule->id, 'base_cents' => $base, 'amount_cents' => $amount,
            'mode' => $native ? 'native' : 'internal', 'status' => $native ? 'settled' : 'pending', 'settled_at' => $native ? now() : null,
        ]);
    }

    /** Estorno: desfaz o split pendente ou registra devolução do que já foi repassado. */
    public function reverseFor(FinancialTransaction $reversal): void
    {
        $split = PaymentSplit::query()->where('transaction_id', $reversal->reversal_of)->lockForUpdate()->first();

        if (! $split || $split->status === 'reversed') {
            return;
        }

        if ($split->status === 'pending' || $split->mode === 'native') {
            // Pendente: simplesmente não será repassado. Nativo: o estorno no gateway desfaz o split.
            $split->update(['status' => 'reversed']);

            return;
        }

        // Já repassado internamente: devolução descontada no próximo fechamento.
        PaymentSplit::create([
            'doctor_id' => $split->doctor_id, 'receivable_id' => $split->receivable_id, 'transaction_id' => $reversal->id,
            'split_rule_id' => $split->split_rule_id, 'base_cents' => -$split->base_cents, 'amount_cents' => -$split->amount_cents,
            'mode' => 'internal', 'status' => 'pending',
        ]);
    }

    /**
     * Fechamento do repasse: soma os repasses internos pendentes do médico no período e
     * gera a conta a pagar (categoria "Repasse médico").
     */
    public function settle(User $actor, string $doctorId, string $from, string $to): Payable
    {
        return DB::transaction(function () use ($actor, $doctorId, $from, $to) {
            $start = CarbonImmutable::parse($from, 'America/Sao_Paulo')->startOfDay()->utc();
            $end = CarbonImmutable::parse($to, 'America/Sao_Paulo')->endOfDay()->utc();

            $splits = PaymentSplit::query()->where('doctor_id', $doctorId)->where('mode', 'internal')->where('status', 'pending')
                ->whereBetween('created_at', [$start, $end])->lockForUpdate()->get();
            $total = (int) $splits->sum('amount_cents');

            if ($splits->isEmpty() || $total <= 0) {
                throw new BusinessRuleViolation('Não há valor de repasse a pagar no período.', 'nothing_to_settle');
            }

            $doctor = DB::table('doctors')->where('id', $doctorId)->first(['name', 'social_name']);
            $category = FinancialCategory::query()->where('type', 'expense')->where('name', 'Repasse médico')->value('id')
                ?? FinancialCategory::query()->where('type', 'expense')->value('id');

            $payable = Payable::create([
                'category_id' => $category, 'supplier' => $doctor->social_name ?: $doctor->name,
                'description' => 'Repasse médico '.CarbonImmutable::parse($from)->format('d/m/Y').' a '.CarbonImmutable::parse($to)->format('d/m/Y')." ({$splits->count()} lançamentos)",
                'amount_cents' => $total, 'due_date' => now('America/Sao_Paulo')->toDateString(), 'created_by' => $actor->id,
            ]);

            PaymentSplit::query()->whereIn('id', $splits->pluck('id'))->update(['status' => 'settled', 'payable_id' => $payable->id, 'settled_at' => now(), 'updated_at' => now()]);
            $this->audit->record('split.settled', $payable, metadata: ['doctor_id' => $doctorId, 'amount_cents' => $total, 'splits' => $splits->count(), 'from' => $from, 'to' => $to]);

            return $payable;
        });
    }

    public function describe(int $cents): string
    {
        return Format::money($cents);
    }
}
