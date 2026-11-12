<?php

namespace App\Services;

use App\Core\Support\Months;
use App\Models\BankAccount;
use App\Models\Entry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Fluxo de caixa e relatórios (por empresa ou consolidado). Agregações feitas no banco. */
class ReportService
{
    /** Série mensal (6 meses antes → 6 depois), saldos das contas bancárias e projeção diária do mês. */
    public function cashflow(Collection $companies, string $month): array
    {
        $ids = $companies->pluck('id');
        $from = Months::add($month, -5);
        $to = Months::add($month, 6);
        $rows = Entry::query()->whereIn('company_id', $ids)->whereBetween('competence', [$from, $to])
            ->selectRaw('competence, type, paid, SUM(amount_cents) as total')->groupBy('competence', 'type', 'paid')->get();

        $series = [];
        for ($m = $from; $m <= $to; $m = Months::add($m, 1)) {
            $r = $rows->where('competence', $m);
            $in = (int) $r->where('type', 'receita')->sum('total');
            $out = (int) $r->where('type', 'despesa')->sum('total');
            $series[] = ['mes' => $m, 'entradas' => $in / 100, 'saidas' => $out / 100, 'pago' => (int) $r->where('type', 'despesa')->where('paid', true)->sum('total') / 100, 'saldo' => ($in - $out) / 100];
        }

        $banks = BankAccount::query()->whereIn('company_id', $ids)->where('active', true)->with('company')->orderBy('name')->get()->map(function ($b) {
            $in = (int) Entry::query()->where('bank_account_id', $b->id)->where('type', 'receita')->sum('amount_cents');
            $out = (int) Entry::query()->where('bank_account_id', $b->id)->where('type', 'despesa')->where('paid', true)->sum('amount_cents');

            return ['id' => $b->id, 'nome' => $b->name, 'banco' => $b->bank, 'empresa' => $b->company->name, 'saldo' => ($b->opening_balance_cents + $in - $out) / 100];
        });

        // projeção do mês: o que entra e o que vence por dia (contas em aberto)
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $days = Entry::query()->whereIn('company_id', $ids)->where('competence', $month)
            ->selectRaw('due_date, type, paid, SUM(amount_cents) as total')->groupBy('due_date', 'type', 'paid')->get()
            ->groupBy(fn ($r) => substr((string) $r->due_date, 0, 10));
        $acc = 0;
        $daily = [];
        for ($d = 1; $d <= $start->daysInMonth; $d++) {
            $key = $start->setDay($d)->toDateString();
            $g = $days->get($key, collect());
            $in = (int) $g->where('type', 'receita')->sum('total');
            $out = (int) $g->where('type', 'despesa')->sum('total');
            $acc += $in - $out;
            $daily[] = ['dia' => $key, 'entra' => $in / 100, 'sai' => $out / 100, 'acumulado' => $acc / 100];
        }

        return ['series' => $series, 'bancos' => $banks->values(), 'diario' => $daily];
    }

    /** Relatório do período: por categoria, por empresa, por situação e lista de lançamentos. */
    public function period(Collection $companies, string $fromMonth, string $toMonth, bool $withList = true): array
    {
        $ids = $companies->pluck('id');
        $names = $companies->pluck('name', 'id');
        $today = today()->toDateString();
        $base = fn () => Entry::query()->whereIn('company_id', $ids)->whereBetween('competence', [$fromMonth, $toMonth]);

        $byCat = $base()->where('type', 'despesa')->selectRaw('category, SUM(amount_cents) as total, COUNT(*) as qtd')->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($r) => ['categoria' => $r->category ?? 'Outros', 'total' => (int) $r->total / 100, 'qtd' => (int) $r->qtd]);
        $byIncomeCat = $base()->where('type', 'receita')->selectRaw('category, SUM(amount_cents) as total')->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($r) => ['categoria' => $r->category ?? 'Outras entradas', 'total' => (int) $r->total / 100]);
        $byCompany = $base()->selectRaw('company_id, type, SUM(amount_cents) as total')->groupBy('company_id', 'type')->get()->groupBy('company_id')
            ->map(fn ($g, $cid) => ['empresa' => $names[$cid] ?? '?', 'entradas' => (int) $g->where('type', 'receita')->sum('total') / 100, 'saidas' => (int) $g->where('type', 'despesa')->sum('total') / 100])
            ->map(fn ($r) => $r + ['resultado' => $r['entradas'] - $r['saidas']])->values();

        $desp = $base()->where('type', 'despesa');
        $status = [
            'pagas' => (int) (clone $desp)->where('paid', true)->sum('amount_cents') / 100,
            'pendentes' => (int) (clone $desp)->where('paid', false)->where('due_date', '>=', $today)->sum('amount_cents') / 100,
            'atrasadas' => (int) (clone $desp)->where('paid', false)->where('due_date', '<', $today)->sum('amount_cents') / 100,
        ];
        $totIn = (int) $base()->where('type', 'receita')->sum('amount_cents') / 100;
        $totOut = (int) $base()->where('type', 'despesa')->sum('amount_cents') / 100;

        $list = $withList ? $base()->with('company', 'bankAccount')->orderBy('due_date')->limit(5000)->get()->map(fn (Entry $e) => [
            'data' => $e->due_date->format('d/m/Y'), 'empresa' => $e->company->name, 'tipo' => $e->type === 'receita' ? 'Receita' : 'Despesa',
            'descricao' => $e->description, 'categoria' => $e->category, 'valor' => $e->amount_cents / 100,
            'situacao' => $e->type === 'receita' ? 'Recebida' : ($e->paid ? 'Paga' : ($e->due_date->toDateString() < $today ? 'Atrasada' : 'Pendente')),
            'pago_em' => $e->paid_at?->format('d/m/Y'), 'conta' => $e->bankAccount?->name, 'origem' => $e->source,
            'parcela' => $e->recurrence_kind === 'parcelada' ? $e->installment_no.'/'.$e->installments_total : ($e->recurrence_kind === 'fixa' ? 'fixa' : ''),
        ]) : collect();

        return ['de' => $fromMonth, 'ate' => $toMonth, 'entradas' => $totIn, 'saidas' => $totOut, 'resultado' => $totIn - $totOut,
            'por_categoria' => $byCat, 'receitas_por_categoria' => $byIncomeCat, 'por_empresa' => $byCompany, 'situacao' => $status, 'lancamentos' => $list];
    }
}
