<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
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
 * atendimento > convênio > pagador > geral) define a parte do médico. Se a cobrança foi feita no ASAAS com a carteira do médico, o próprio
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

        return $this->ruleForDoctor($r->doctor_id, $serviceId, $r->payer_type, null);
    }

    /** Regra mais específica: tipo de atendimento > convênio > pagador > geral (empate: a mais recente). */
    public function ruleForDoctor(string $doctorId, ?string $serviceId, ?string $payerType, ?string $insurerId): ?SplitRule
    {
        return SplitRule::query()->where('doctor_id', $doctorId)->where('is_active', true)->get()
            ->filter(fn (SplitRule $rule) => ($rule->doctor_service_id === null || $rule->doctor_service_id === $serviceId)
                && ($rule->payer_type === null || $rule->payer_type === $payerType)
                && ($rule->insurer_id === null || $rule->insurer_id === $insurerId))
            ->sortByDesc(fn (SplitRule $rule) => [($rule->doctor_service_id ? 4 : 0) + ($rule->insurer_id ? 2 : 0) + ($rule->payer_type ? 1 : 0), $rule->created_at?->timestamp])
            ->first();
    }

    /**
     * Pagamento de lote de convênio: um recebimento cobre guias de vários médicos. A parte de
     * cada médico é calculada guia a guia (regra da guia) e somada num repasse interno por médico.
     *
     * @param  iterable<array{doctor_id: string, service_id: ?string, insurer_id: string, paid_cents: int}>  $guides
     */
    public function applyForInsurance(FinancialTransaction $txn, iterable $guides): void
    {
        $byDoctor = [];

        foreach ($guides as $g) {
            if ($g['paid_cents'] <= 0 || ! ($rule = $this->ruleForDoctor($g['doctor_id'], $g['service_id'], 'insurance', $g['insurer_id']))) {
                continue;
            }
            $byDoctor[$g['doctor_id']] ??= ['base' => 0, 'amount' => 0, 'rules' => []];
            $byDoctor[$g['doctor_id']]['base'] += $g['paid_cents'];
            $byDoctor[$g['doctor_id']]['amount'] += $rule->shareOf($g['paid_cents']);
            $byDoctor[$g['doctor_id']]['rules'][$rule->id] = true;
        }

        foreach ($byDoctor as $doctorId => $d) {
            if ($d['amount'] <= 0) {
                continue;
            }
            PaymentSplit::create([
                'doctor_id' => $doctorId, 'receivable_id' => $txn->receivable_id, 'transaction_id' => $txn->id,
                'split_rule_id' => count($d['rules']) === 1 ? array_key_first($d['rules']) : null,
                'base_cents' => $d['base'], 'amount_cents' => $d['amount'], 'mode' => 'internal', 'status' => 'pending', 'source' => null,
            ]);
        }
    }

    /**
     * Split nativo para enviar ao gateway — só com regra e identificação do médico no gateway:
     * ASAAS → carteira (walletId); Cielo API E-commerce → SubordinateMerchantId.
     */
    public function nativePayload(Receivable $r, PaymentGateway $gateway, int $amount): ?array
    {
        if (! in_array($gateway->provider, ['asaas', 'cielo_api'], true) || ! ($rule = $this->ruleFor($r))) {
            return null;
        }

        $doctor = DB::table('doctors')->where('id', $r->doctor_id)->first(['asaas_wallet_id', 'cielo_subordinate_id']);
        $base = ['doctor_id' => $r->doctor_id, 'rule_id' => $rule->id, 'source' => $gateway->provider, 'expected_cents' => $rule->shareOf($amount)];

        return match (true) {
            $gateway->provider === 'asaas' && (bool) $doctor?->asaas_wallet_id => $base + ['wallet_id' => $doctor->asaas_wallet_id, 'type' => $rule->type, 'value' => $rule->value],
            // Cielo: valor em centavos da parte do médico (a clínica fica com o restante).
            $gateway->provider === 'cielo_api' && (bool) $doctor?->cielo_subordinate_id => $base + ['subordinate_id' => $doctor->cielo_subordinate_id],
            default => null,
        };
    }

    /** O médico pode receber split na maquininha Cielo (subordinado cadastrado + regra)? */
    public function terminalSplitAvailable(Receivable $r): bool
    {
        return $r->doctor_id && $this->ruleFor($r)
            && (bool) DB::table('doctors')->where('id', $r->doctor_id)->value('cielo_subordinate_id');
    }

    /**
     * @param  string|null  $terminalSource  "cielo_terminal" quando a venda foi feita na maquininha Cielo com split
     *                                       (a Cielo já dividiu — não há repasse interno a pagar)
     */
    public function applyForReceipt(FinancialTransaction $txn, ?PaymentCharge $charge = null, ?string $terminalSource = null): ?PaymentSplit
    {
        if ($txn->kind !== 'receipt' || ! $txn->receivable_id) {
            return null;
        }

        $r = Receivable::query()->find($txn->receivable_id);
        if (! $r || ! ($rule = $this->ruleFor($r))) {
            return null;
        }

        $native = ($charge?->split_snapshot && ($charge->split_snapshot['doctor_id'] ?? null) === $r->doctor_id) || $terminalSource !== null;
        $source = $terminalSource ?? ($native ? ($charge->split_snapshot['source'] ?? $charge->provider) : null);
        // Split nativo do ASAAS incide sobre o valor líquido (após a tarifa); na Cielo, sobre o valor da venda.
        $base = $native && $charge?->net_cents ? $charge->net_cents : $txn->amount_cents;
        $amount = $rule->shareOf($base);

        if ($amount <= 0) {
            return null;
        }

        return PaymentSplit::create([
            'doctor_id' => $r->doctor_id, 'receivable_id' => $r->id, 'transaction_id' => $txn->id, 'charge_id' => $charge?->id,
            'split_rule_id' => $rule->id, 'base_cents' => $base, 'amount_cents' => $amount,
            'mode' => $native ? 'native' : 'internal', 'status' => $native ? 'settled' : 'pending', 'settled_at' => $native ? now() : null,
            'source' => $source,
        ]);
    }

    /** Estorno: desfaz o split pendente ou registra devolução do que já foi repassado. */
    public function reverseFor(FinancialTransaction $reversal): void
    {
        // Um recebimento pode ter splits de vários médicos (lote de convênio).
        $splits = PaymentSplit::query()->where('transaction_id', $reversal->reversal_of)->where('status', '!=', 'reversed')->lockForUpdate()->get();

        foreach ($splits as $split) {
            if ($split->status === 'pending' || $split->mode === 'native') {
                // Pendente: simplesmente não será repassado. Nativo: o estorno no gateway desfaz o split.
                $split->update(['status' => 'reversed']);

                continue;
            }

            // Já repassado internamente: devolução descontada no próximo fechamento.
            PaymentSplit::create([
                'doctor_id' => $split->doctor_id, 'receivable_id' => $split->receivable_id, 'transaction_id' => $reversal->id,
                'split_rule_id' => $split->split_rule_id, 'base_cents' => -$split->base_cents, 'amount_cents' => -$split->amount_cents,
                'mode' => 'internal', 'status' => 'pending',
            ]);
        }
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
}
