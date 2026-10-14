<?php

namespace App\Modules\Sprint\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Sprint\Jobs\IssueSprintNfse;
use App\Modules\Sprint\Models\SplitMath;
use App\Modules\Sprint\Models\SprintPayment;
use App\Modules\Sprint\Models\SprintProfessional;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Models\SprintSetting;
use App\Modules\Sprint\Models\SprintSplit;
use Illuminate\Support\Facades\DB;

/**
 * Livro da divisão do Melhor Sprint.
 *
 * 1) Ao criar um pagamento, calcula a DIVISÃO planejada (allocation): parte de cada profissional
 *    (proporcional ao valor pago; o último pagamento fecha os centavos), comissão da plataforma e
 *    o que o gateway vai separar sozinho (split nativo: carteira ASAAS / subordinado Cielo).
 * 2) Quando o pagamento é confirmado (na hora, ou pelo webhook + consulta ao gateway), grava os
 *    lançamentos: profissional (nativo = já recebeu; interno = a repassar), clínica, comissão
 *    (nativa = já separada; interna = a cobrar) e tarifa do gateway.
 * 3) Estorno do recebimento desfaz a divisão (ou gera devolução do que já foi repassado).
 */
class SprintLedger
{
    public function __construct(private readonly CommissionService $commission) {}

    /**
     * @return array{professionals: array<string, array{cents: int, native: bool}>, platform: array{cents: int, native: bool},
     *     house: int, source: ?string, asaas: list<array{wallet_id: string, fixed_cents: int}>, cielo: list<array{subordinate_id: string, amount_cents: int}>}
     */
    public function allocate(SprintSale $sale, int $amount, string $channel, bool $terminalSplit = false): array
    {
        $proTotals = [];
        foreach ($sale->items()->whereNotNull('professional_id')->get(['professional_id', 'professional_cents']) as $item) {
            $proTotals[$item->professional_id] = ($proTotals[$item->professional_id] ?? 0) + $item->professional_cents;
        }

        // Reservado = pagamentos confirmados + cobranças ainda em aberto (ordem de chegada).
        $allocated = [];
        $reserved = 0;
        foreach ($this->activePayments($sale) as $p) {
            $reserved += $p->amount_cents;
            foreach ($p->allocation['professionals'] ?? [] as $id => $part) {
                $allocated[$id] = ($allocated[$id] ?? 0) + (int) $part['cents'];
            }
        }

        $isLast = $reserved + $amount >= $sale->total_cents;
        $professionals = SprintProfessional::query()->whereIn('id', array_keys($proTotals))->get()->keyBy('id');
        $wallet = $this->commission->platformWallet();
        $subordinate = $this->commission->platformSubordinate();

        $result = ['professionals' => [], 'platform' => ['cents' => 0, 'native' => false], 'house' => 0, 'source' => null, 'asaas' => [], 'cielo' => []];
        $sharesTotal = 0;

        foreach ($proTotals as $id => $total) {
            $left = $total - ($allocated[$id] ?? 0);
            $cents = max(0, $isLast ? $left : min($left, SplitMath::prorate($amount, $total, $sale->total_cents)));
            $pro = $professionals[$id];
            [$native, $source] = $this->nativeFor($channel, $terminalSplit, $pro->asaas_wallet_id, $pro->cielo_subordinate_id);

            $result['professionals'][$id] = ['cents' => $cents, 'native' => $native && $cents > 0];
            $sharesTotal += $cents;

            if ($native && $cents > 0) {
                $result['source'] = $source;
                match ($source) {
                    'asaas', 'mock' => $result['asaas'][] = ['wallet_id' => $pro->asaas_wallet_id, 'fixed_cents' => $cents],
                    'cielo_api' => $result['cielo'][] = ['subordinate_id' => $pro->cielo_subordinate_id, 'amount_cents' => $cents],
                    default => null,
                };
            }
        }

        $commission = SplitMath::bp($amount, $sale->commission_bp);
        $platformNative = $commission > 0 && match ($channel) {
            'asaas', 'mock' => (bool) $wallet,
            'cielo_api' => (bool) $subordinate,
            default => false,
        };
        $result['platform'] = ['cents' => $commission, 'native' => $platformNative];
        if ($platformNative) {
            $result['source'] ??= $channel;
            $channel === 'cielo_api'
                ? $result['cielo'][] = ['subordinate_id' => $subordinate, 'amount_cents' => $commission]
                : $result['asaas'][] = ['wallet_id' => $wallet, 'fixed_cents' => $commission];
        }

        $result['house'] = $amount - $sharesTotal - $commission;

        $nativeTotal = array_sum(array_column($result['asaas'], 'fixed_cents')) + array_sum(array_column($result['cielo'], 'amount_cents'));
        if ($nativeTotal >= $amount) {
            throw new BusinessRuleViolation('As partes separadas pelo gateway (profissionais + comissão) não podem consumir todo o pagamento — a tarifa do gateway sai da parte da clínica. Revise as regras de divisão.', 'split_exceeds_amount');
        }

        return $result;
    }

    /** Pagamentos que contam para a divisão: confirmados e cobranças ainda em aberto. */
    public function activePayments(SprintSale $sale)
    {
        return SprintPayment::query()->with('charge:id,status')->where('sale_id', $sale->id)->whereIn('status', ['paid', 'pending'])->get()
            ->filter(fn (SprintPayment $p) => match (true) {
                $p->status === 'paid' => true,
                (bool) $p->charge_id => $p->charge?->isOpen() || $p->charge?->status === 'review',
                // registro sendo confirmado agora; um pendente "órfão" (falha no meio) não trava a venda
                default => $p->created_at?->gt(now()->subMinutes(10)) ?? true,
            });
    }

    /** Hook do SplitService: recebimento online confirmado de uma cobrança do Sprint. */
    public function onChargeReceipt(FinancialTransaction $txn, PaymentCharge $charge): bool
    {
        $payment = SprintPayment::query()->where('charge_id', $charge->id)->lockForUpdate()->first();
        if (! $payment) {
            return false;
        }

        $fee = $charge->net_cents !== null ? max(0, $txn->amount_cents - $charge->net_cents) : 0;
        $this->record($payment, $txn, $fee, $charge->provider);
        $payment->forceFill(['status' => 'paid', 'transaction_id' => $txn->id, 'paid_at' => $txn->occurred_at ?? now()])->save();
        $this->refreshSale($payment->sale_id);

        return true;
    }

    /** Grava a divisão de um pagamento confirmado. */
    public function record(SprintPayment $payment, FinancialTransaction $txn, int $feeCents = 0, ?string $source = null): void
    {
        $a = $payment->allocation ?? [];
        $base = ['sale_id' => $payment->sale_id, 'payment_id' => $payment->id, 'transaction_id' => $txn->id];
        $nativeSource = $a['source'] ?? $source;

        foreach ($a['professionals'] ?? [] as $id => $part) {
            if ((int) $part['cents'] <= 0) {
                continue;
            }
            SprintSplit::create($base + [
                'party' => 'professional', 'professional_id' => $id, 'amount_cents' => (int) $part['cents'],
                'mode' => $part['native'] ? 'native' : 'internal', 'status' => $part['native'] ? 'settled' : 'pending',
                'source' => $part['native'] ? $nativeSource : null, 'settled_at' => $part['native'] ? now() : null,
            ]);
        }

        $platform = $a['platform'] ?? ['cents' => 0, 'native' => false];
        if ((int) $platform['cents'] > 0) {
            SprintSplit::create($base + [
                'party' => 'platform', 'amount_cents' => (int) $platform['cents'], 'mode' => $platform['native'] ? 'native' : 'internal',
                'status' => $platform['native'] ? 'settled' : 'to_invoice', 'source' => $platform['native'] ? $nativeSource : null,
                'settled_at' => $platform['native'] ? now() : null,
            ]);
        }

        if ($feeCents > 0) {
            SprintSplit::create($base + ['party' => 'fee', 'amount_cents' => $feeCents, 'mode' => 'native', 'status' => 'settled', 'source' => $source, 'settled_at' => now()]);
        }

        // Parte da clínica: o que sobra depois dos profissionais, da comissão e da tarifa.
        $house = (int) ($a['house'] ?? 0) - $feeCents;
        if ($house !== 0) {
            SprintSplit::create($base + ['party' => 'house', 'amount_cents' => $house, 'mode' => 'internal', 'status' => 'settled', 'settled_at' => now()]);
        }
    }

    /** Hook do SplitService: estorno de um recebimento — desfaz a divisão do pagamento. */
    public function onReversal(FinancialTransaction $reversal): void
    {
        $splits = SprintSplit::query()->where('transaction_id', $reversal->reversal_of)->where('status', '!=', 'reversed')->lockForUpdate()->get();
        if ($splits->isEmpty()) {
            return;
        }

        foreach ($splits as $s) {
            $alreadyPaid = ($s->party === 'professional' && $s->mode === 'internal' && $s->status === 'settled')
                || ($s->party === 'platform' && $s->status === 'invoiced');

            if (! $alreadyPaid) {
                $s->update(['status' => 'reversed']);

                continue;
            }

            // Já repassado ao profissional / já cobrado da clínica: devolução no próximo fechamento.
            SprintSplit::create([
                'sale_id' => $s->sale_id, 'payment_id' => $s->payment_id, 'transaction_id' => $reversal->id, 'party' => $s->party,
                'professional_id' => $s->professional_id, 'amount_cents' => -$s->amount_cents, 'mode' => 'internal',
                'status' => $s->party === 'platform' ? 'to_invoice' : 'pending',
            ]);
        }

        SprintPayment::query()->where('transaction_id', $reversal->reversal_of)->update(['status' => 'refunded', 'updated_at' => now()]);
        $this->refreshSale($splits->first()->sale_id);
    }

    /** Situação da venda a partir da conta a receber; dispara a NFS-e automática quando quitada. */
    public function refreshSale(string $saleId): SprintSale
    {
        $sale = SprintSale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();
        if ($sale->status === 'cancelled') {
            return $sale;
        }

        $r = Receivable::query()->find($sale->receivable_id);
        $refunded = SprintPayment::query()->where('sale_id', $sale->id)->where('status', 'refunded')->exists();
        $wasPaid = $sale->status === 'paid';

        $sale->status = match (true) {
            $r->status === 'paid' => 'paid',
            $r->paid_cents > 0 => 'partial',
            $refunded => 'refunded',
            default => 'open',
        };
        $sale->paid_at = $sale->status === 'paid' ? ($sale->paid_at ?? now()) : null;
        $sale->save();

        if (! $wasPaid && $sale->status === 'paid' && $sale->issue_nfse) {
            $settings = SprintSetting::current();
            if ($settings->nfse_enabled && $settings->nfse_auto) {
                $companyId = $sale->company_id;
                DB::afterCommit(fn () => IssueSprintNfse::dispatch($companyId, $saleId));
            }
        }

        return $sale;
    }

    /** @return array{0: bool, 1: ?string} [o gateway separa sozinho?, origem] */
    private function nativeFor(string $channel, bool $terminalSplit, ?string $wallet, ?string $subordinate): array
    {
        return match (true) {
            in_array($channel, ['asaas', 'mock'], true) && (bool) $wallet => [true, $channel],
            $channel === 'cielo_api' && (bool) $subordinate => [true, 'cielo_api'],
            $channel === 'cielo_terminal' && $terminalSplit && (bool) $subordinate => [true, 'cielo_terminal'],
            default => [false, null],
        };
    }
}
