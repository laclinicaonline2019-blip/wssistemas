<?php

namespace App\Services\Reports;

use App\Core\Support\Money;
use App\Core\Support\Months;
use App\Models\Budget;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Entry;
use App\Services\Assistant\AiInterpreter;
use App\Services\CategoryGuesser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Relatório de UMA entidade (CPF ou CNPJ) num período — semanal ou mensal — e as recomendações de economia.
 *
 * Tudo vem dos lançamentos EFETIVOS do banco (pendentes de aprovação não entram). As recomendações são regras
 * sobre esses números; nenhuma economia é garantida (o texto diz "cerca de"/"estimativa"). A reescrita opcional pela
 * IA só é aceita se não trouxer nenhum número que não esteja nos dados.
 */
class PeriodReport
{
    /** Categorias em que costuma haver espaço para cortar (sugestão de economia). */
    private const FLEXIBLE = ['Delivery e lanches', 'Refeições', 'Lazer', 'Assinaturas', 'Compras', 'Combustível', 'Marketing'];

    public function __construct(private readonly AiInterpreter $ai) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} início, fim e chave do período ANTERIOR completo */
    public static function lastPeriod(string $kind, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        if ($kind === 'mensal') {
            $from = $today->startOfMonth()->subMonthNoOverflow();

            return [$from, $from->endOfMonth()->startOfDay(), $from->format('Y-m')];
        }
        $from = $today->startOfWeek(CarbonImmutable::MONDAY)->subWeek();

        return [$from, $from->addDays(6), $from->format('o-\WW')];
    }

    /** Período em andamento (semana ou mês atual até hoje). */
    public static function currentPeriod(string $kind, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $from = $kind === 'mensal' ? $today->startOfMonth() : $today->startOfWeek(CarbonImmutable::MONDAY);

        return [$from, $today, $kind === 'mensal' ? $from->format('Y-m') : $from->format('o-\WW')];
    }

    public static function label(string $kind, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $kind === 'mensal' ? Months::label($from->format('Y-m')) : 'semana de '.$from->format('d/m').' a '.$to->format('d/m/Y');
    }

    /** Números do período e do período anterior de mesma duração. */
    public function facts(Company $company, string $kind, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $from->diffInDays($to) + 1;
        [$pFrom, $pTo] = $kind === 'mensal'
            ? [$from->subMonthNoOverflow()->startOfMonth(), $from->subMonthNoOverflow()->endOfMonth()->startOfDay()]
            : [$from->subDays($days), $from->subDay()];

        $cur = $this->rows($company, $from, $to);
        $prev = $this->rows($company, $pFrom, $pTo);
        $sum = fn (Collection $r, string $t) => (int) $r->where('type', $t)->sum('amount_cents');

        $exp = $cur->where('type', 'despesa');
        $prevByCat = $prev->where('type', 'despesa')->groupBy(fn ($e) => $e->category ?: 'Outros')->map(fn ($g) => (int) $g->sum('amount_cents'));
        $totalExp = $sum($cur, 'despesa');
        $byCat = $exp->groupBy(fn ($e) => $e->category ?: 'Outros')->map(fn ($g, $cat) => [
            'categoria' => $cat, 'total' => (int) $g->sum('amount_cents'), 'anterior' => (int) ($prevByCat[$cat] ?? 0), 'qtd' => $g->count(),
            'pct' => $totalExp > 0 ? round($g->sum('amount_cents') * 100 / $totalExp, 1) : 0,
        ])->sortByDesc('total')->values()->all();

        $centers = CostCenter::query()->where('company_id', $company->id)->pluck('name', 'id');
        $byCenter = $exp->whereNotNull('cost_center_id')->groupBy('cost_center_id')
            ->map(fn ($g, $id) => ['centro' => $centers[$id] ?? '—', 'total' => (int) $g->sum('amount_cents')])->sortByDesc('total')->values()->all();

        return [
            'empresa' => $company->name, 'company_id' => $company->id, 'tipo_entidade' => $company->isPerson() ? 'pf' : 'pj',
            'tipo' => $kind, 'de' => $from->toDateString(), 'ate' => $to->toDateString(), 'periodo' => self::label($kind, $from, $to),
            'receitas' => $sum($cur, 'receita'), 'despesas' => $totalExp,
            'pagas' => (int) $exp->where('paid', true)->sum('amount_cents'), 'em_aberto' => (int) $exp->where('paid', false)->sum('amount_cents'),
            'saldo' => $sum($cur, 'receita') - $totalExp,
            'anterior' => ['de' => $pFrom->toDateString(), 'ate' => $pTo->toDateString(), 'receitas' => $sum($prev, 'receita'), 'despesas' => $sum($prev, 'despesa')],
            'qtd' => $cur->count(),
            'categorias' => $byCat,
            'centros' => $byCenter,
            'metas' => $this->goals($company, $kind === 'mensal' ? 'mes' : 'semana', $exp),
            'recorrentes' => $this->recurring($company, $to),
            'pendentes' => (int) Entry::withPending()->where('company_id', $company->id)->where('approval_status', Entry::PENDING)->count(),
        ];
    }

    /** Recomendações (regras sobre os números do período). @return list<string> */
    public function insights(array $f): array
    {
        $out = [];
        $unit = $f['tipo'] === 'mensal' ? 'por mês' : 'por semana';
        if ($f['qtd'] === 0) {
            return ['Nenhum lançamento no período. Lance seus gastos pelo WhatsApp (ex.: "100 almoço") para acompanhar a saúde financeira.'];
        }
        $prev = $f['anterior']['despesas'];
        if ($prev > 0 && $f['despesas'] > 0) {
            $var = ($f['despesas'] - $prev) * 100 / $prev;
            if ($var >= 10) {
                $out[] = '📈 Os gastos subiram '.self::pct($var).' em relação ao período anterior ('.Money::brl($prev).' → '.Money::brl($f['despesas']).').';
            } elseif ($var <= -10) {
                $out[] = '📉 Os gastos caíram '.self::pct(-$var).' em relação ao período anterior: '.Money::brl($prev - $f['despesas']).' a menos.';
            }
        }
        $up = collect($f['categorias'])->filter(fn ($c) => $c['anterior'] > 0 && $c['total'] - $c['anterior'] >= 5000 && ($c['total'] - $c['anterior']) * 100 / $c['anterior'] >= 20)
            ->sortByDesc(fn ($c) => $c['total'] - $c['anterior'])->first();
        if ($up) {
            $out[] = '🔎 Maior aumento: '.$up['categoria'].', +'.Money::brl($up['total'] - $up['anterior']).' (+'.self::pct(($up['total'] - $up['anterior']) * 100 / $up['anterior']).').';
        }
        $top = $f['categorias'][0] ?? null;
        if ($top && $top['pct'] >= 35 && count($f['categorias']) > 1) {
            $out[] = '🥇 '.$top['categoria'].' concentra '.self::pct($top['pct']).' das despesas ('.Money::brl($top['total']).').';
        }
        foreach ($f['metas'] as $m) {
            if ($m['gasto'] > $m['meta']) {
                $out[] = '🚨 Meta '.$m['rotulo'].' estourada: '.Money::brl($m['gasto']).' de '.Money::brl($m['meta']).' (+'.Money::brl($m['gasto'] - $m['meta']).').';
            } elseif ($m['pct'] >= (int) config('wsf.reports.goal_warning_percent', 80)) {
                $out[] = '⚠️ Meta '.$m['rotulo'].': '.self::pct($m['pct']).' usada ('.Money::brl($m['gasto']).' de '.Money::brl($m['meta']).').';
            }
        }
        if ($f['recorrentes']) {
            $total = array_sum(array_column($f['recorrentes'], 'valor'));
            $names = implode(', ', array_slice(array_column($f['recorrentes'], 'descricao'), 0, 3));
            $out[] = '🔁 Gastos que se repetem todo mês somam cerca de '.Money::brl($total).' ('.$names.'). Revise os que não usa mais.';
        }
        $flex = collect($f['categorias'])->filter(fn ($c) => in_array($c['categoria'], self::FLEXIBLE, true) && $c['total'] >= 5000)->first();
        if ($flex) {
            $save = (int) round($flex['total'] * 0.2);
            $out[] = '💡 Oportunidade: reduzir 20% em '.$flex['categoria'].' economizaria cerca de '.Money::brl($save).' '.$unit.' (estimativa com base neste período).';
        }
        if ($f['saldo'] < 0 && $f['receitas'] > 0) {
            $out[] = '❗ As despesas superaram as receitas em '.Money::brl(-$f['saldo']).'.';
        }
        if ($f['pendentes'] > 0) {
            $out[] = '⏳ '.$f['pendentes'].' despesa(s) aguardando aprovação (não entram nos totais até serem aprovadas).';
        }
        if (! $out) {
            $out[] = '✅ Gastos estáveis em relação ao período anterior. Continue lançando para acompanhar.';
        }

        return $out;
    }

    /** Texto do relatório (WhatsApp e e-mail). */
    public function render(array $f, ?array $insights = null): string
    {
        $insights ??= $this->insights($f);
        $prevExp = $f['anterior']['despesas'];
        $lines = ['📊 *Relatório '.($f['tipo'] === 'mensal' ? 'mensal' : 'semanal').' — '.$f['empresa'].'*', $f['periodo'], '',
            '💵 Receitas: '.Money::brl($f['receitas']),
            '💸 Despesas: '.Money::brl($f['despesas']).($f['em_aberto'] > 0 ? ' (em aberto '.Money::brl($f['em_aberto']).')' : ''),
            '💰 Saldo: '.Money::brl($f['saldo']),
            '↔️ Período anterior: despesas '.Money::brl($prevExp).($prevExp > 0 ? ' ('.self::signedPct(($f['despesas'] - $prevExp) * 100 / $prevExp).')' : ''),
        ];
        if ($f['categorias']) {
            $lines[] = '';
            $lines[] = '*Principais categorias*';
            foreach (array_slice($f['categorias'], 0, 5) as $c) {
                $lines[] = CategoryGuesser::emoji($c['categoria']).' '.$c['categoria'].': '.Money::brl($c['total']).' ('.self::pct($c['pct']).')';
            }
        }
        if ($f['centros']) {
            $lines[] = '';
            $lines[] = '*Centros de custo*';
            foreach (array_slice($f['centros'], 0, 5) as $c) {
                $lines[] = '🏷️ '.$c['centro'].': '.Money::brl($c['total']);
            }
        }
        $lines[] = '';
        $lines[] = '*Recomendações*';
        foreach ($insights as $i) {
            $lines[] = $i;
        }

        return implode("\n", $lines);
    }

    /**
     * Reescrita opcional pela IA (WSF_RELATORIOS_IA=true e plano com IA): só é aceita se TODOS os números do texto
     * novo existirem nos dados. Senão, ficam as recomendações por regras.
     *
     * @param  list<string>  $insights
     * @return list<string>
     */
    public function polish(string $accountId, array $f, array $insights): array
    {
        if (! config('wsf.reports.ai_text') || ! $this->ai->available()) {
            return $insights;
        }
        $prompt = "Reescreva as recomendações financeiras abaixo em português do Brasil, em tom acolhedor e direto, uma por linha, começando cada linha com o mesmo emoji. "
            ."Use SOMENTE os números que já aparecem no texto, sem criar valores, porcentagens ou promessas de economia garantida. Não acrescente recomendações.\n\n".implode("\n", $insights);
        $text = $this->ai->analysis($accountId, $prompt);
        if (! $text) {
            return $insights;
        }
        $allowed = self::numbers(implode(' ', $insights));
        foreach (self::numbers($text) as $n) {
            if (! in_array($n, $allowed, true)) {
                return $insights;   // número inventado: descarta a reescrita
            }
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));

        return $lines ?: $insights;
    }

    /** @return list<string> números do texto, normalizados (1.234,56 → 1234,56) */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d[\d.]*(?:,\d+)?/u', $text, $m);

        return array_values(array_unique(array_map(fn ($n) => rtrim(str_replace('.', '', $n), ','), $m[0])));
    }

    /** Situação das metas no período (mensal "mes" ou semanal "semana"). */
    public function goals(Company $company, string $period, Collection $expenses): array
    {
        $out = [];
        foreach (Budget::query()->where('company_id', $company->id)->where('period', $period)->orderBy('category')->get() as $b) {
            $spent = (int) ($b->category === '' ? $expenses->sum('amount_cents') : $expenses->where('category', $b->category)->sum('amount_cents'));
            $out[] = ['rotulo' => ($period === 'mes' ? 'mensal' : 'semanal').($b->category === '' ? '' : ' de '.$b->category), 'categoria' => $b->category,
                'meta' => $b->amount_cents, 'gasto' => $spent, 'pct' => $b->amount_cents > 0 ? round($spent * 100 / $b->amount_cents, 1) : 0];
        }

        return $out;
    }

    /** Avisos de meta logo depois de lançar uma despesa (mês e semana da data do gasto). @return list<string> */
    public function goalWarnings(Company $company, ?string $category, CarbonImmutable $date): array
    {
        $warn = [];
        foreach (['mes' => [$date->startOfMonth(), $date->endOfMonth()->startOfDay()], 'semana' => [$date->startOfWeek(CarbonImmutable::MONDAY), $date->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()]] as $period => [$from, $to]) {
            $budgets = Budget::query()->where('company_id', $company->id)->where('period', $period)->whereIn('category', array_filter(['', (string) $category], fn ($c) => $c !== null))->get();
            if ($budgets->isEmpty()) {
                continue;
            }
            $exp = $this->rows($company, $from, $to)->where('type', 'despesa');
            foreach ($this->goals($company, $period, $exp) as $g) {
                if ($g['categoria'] !== '' && $g['categoria'] !== $category) {
                    continue;
                }
                if ($g['gasto'] > $g['meta']) {
                    $warn[] = '🚨 Meta '.$g['rotulo'].' estourada: '.Money::brl($g['gasto']).' de '.Money::brl($g['meta']).'.';
                } elseif ($g['pct'] >= (int) config('wsf.reports.goal_warning_percent', 80)) {
                    $warn[] = '⚠️ Meta '.$g['rotulo'].': '.self::pct($g['pct']).' usada ('.Money::brl($g['gasto']).' de '.Money::brl($g['meta']).').';
                }
            }
        }

        return $warn;
    }

    /** Gastos que se repetem: série fixa ou mesma descrição em pelo menos 2 dos últimos 3 meses. */
    private function recurring(Company $company, CarbonImmutable $to): array
    {
        $from = $to->startOfMonth()->subMonthsNoOverflow(2);
        $rows = Entry::query()->where('company_id', $company->id)->where('type', 'despesa')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->get(['description', 'amount_cents', 'competence', 'recurrence_kind']);
        $groups = $rows->groupBy(fn ($e) => CategoryGuesser::norm($e->description));
        $out = [];
        foreach ($groups as $g) {
            $months = $g->pluck('competence')->unique()->count();
            if ($months >= 2 || $g->contains('recurrence_kind', 'fixa')) {
                $out[] = ['descricao' => $g->first()->description, 'valor' => (int) round($g->sum('amount_cents') / max(1, $months))];
            }
        }
        usort($out, fn ($a, $b) => $b['valor'] <=> $a['valor']);

        return array_slice($out, 0, 8);
    }

    private function rows(Company $company, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Entry::query()->where('company_id', $company->id)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'type', 'amount_cents', 'category', 'paid', 'cost_center_id', 'description']);
    }

    private static function pct(float $v): string
    {
        return str_replace('.', ',', (string) round($v, $v < 10 ? 1 : 0)).'%';
    }

    private static function signedPct(float $v): string
    {
        return ($v >= 0 ? '+' : '−').self::pct(abs($v));
    }
}
