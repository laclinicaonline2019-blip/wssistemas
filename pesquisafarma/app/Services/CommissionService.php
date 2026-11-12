<?php

namespace App\Services;

use App\Models\CommissionStatement;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Receipt;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Fechamento de comissão por farmácia — semanal ou mensal, conforme a regra do cadastro. */
class CommissionService
{
    /** Período anterior completo para o ciclo. @return array{0:CarbonImmutable,1:CarbonImmutable} */
    public static function previousPeriod(string $cycle, ?CarbonImmutable $ref = null): array
    {
        $ref ??= CarbonImmutable::today();
        if ($cycle === 'semanal') {
            $start = $ref->startOfWeek()->subWeek();

            return [$start, $start->endOfWeek()->startOfDay()];
        }
        $start = $ref->startOfMonth()->subMonth();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    /** Vendas ainda não fechadas no período (pedidos + notas avulsas conferidas). */
    public function pending(Pharmacy $pharmacy, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $orders = Order::query()->where('pharmacy_id', $pharmacy->id)->where('status', '!=', 'cancelado')->whereNull('commission_statement_id')
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])->get();
        $receipts = Receipt::query()->where('pharmacy_id', $pharmacy->id)->where('status', 'conferida')->whereNull('order_id')->whereNull('commission_statement_id')
            ->whereBetween(DB::raw('DATE(COALESCE(purchase_date, reviewed_at))'), [$start->toDateString(), $end->toDateString()])->get();

        return [$orders, $receipts];
    }

    public function generate(Pharmacy $pharmacy, CarbonImmutable $start, CarbonImmutable $end, ?int $userId = null): CommissionStatement
    {
        if ($end->lt($start)) {
            throw new InvalidArgumentException('Período inválido.');
        }
        $exists = CommissionStatement::query()->where('pharmacy_id', $pharmacy->id)->where('status', '!=', 'cancelado')
            ->where('period_start', '<=', $end->toDateString())->where('period_end', '>=', $start->toDateString())->exists();
        if ($exists) {
            throw new InvalidArgumentException('Já existe fechamento para este período.');
        }

        return DB::transaction(function () use ($pharmacy, $start, $end, $userId) {
            [$orders, $receipts] = $this->pending($pharmacy, $start, $end);
            $base = $orders->sum('commission_base') + $receipts->sum('total');
            $amount = $orders->sum('commission_amount') + $receipts->sum('commission_amount');
            $st = CommissionStatement::create([
                'pharmacy_id' => $pharmacy->id, 'cycle' => $pharmacy->commission_cycle, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'sales_count' => $orders->count() + $receipts->count(), 'base_total' => round($base, 2), 'commission_amount' => round($amount, 2), 'created_by' => $userId,
            ]);
            Order::query()->whereIn('id', $orders->pluck('id'))->update(['commission_statement_id' => $st->id]);
            Receipt::query()->whereIn('id', $receipts->pluck('id'))->update(['commission_statement_id' => $st->id]);
            Activity::log('comissao.fechamento', "Fechamento {$pharmacy->trade_name} {$start->format('d/m')}–{$end->format('d/m/Y')}: ".money($amount), $st, userId: $userId);

            return $st;
        });
    }

    /** Gera os fechamentos vencidos (rodado pelo agendador). */
    public function generateDue(): int
    {
        $n = 0;
        foreach (Pharmacy::query()->where('active', true)->get() as $p) {
            [$start, $end] = self::previousPeriod($p->commission_cycle);
            try {
                $this->generate($p, $start, $end);
                $n++;
            } catch (InvalidArgumentException) {
                // já fechado
            }
        }

        return $n;
    }

    public function cancel(CommissionStatement $st, ?int $userId = null): void
    {
        DB::transaction(function () use ($st) {
            Order::query()->where('commission_statement_id', $st->id)->update(['commission_statement_id' => null]);
            Receipt::query()->where('commission_statement_id', $st->id)->update(['commission_statement_id' => null]);
            $st->update(['status' => 'cancelado']);
        });
        Activity::log('comissao.cancelada', 'Fechamento #'.$st->id.' cancelado', $st, userId: $userId);
    }
}
