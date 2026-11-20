<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Reports\PeriodReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Painel simplificado (1.1.7): para UMA entidade (CPF ou CNPJ) — entradas, despesas, saldo, comparação com o período
 * anterior, metas, categorias, centros de custo e recomendações. Mesmos números dos relatórios automáticos.
 */
class SummaryController extends Controller
{
    public const PERIODS = ['mes' => 'Este mês', 'semana' => 'Esta semana', 'mes_passado' => 'Mês passado', 'semana_passada' => 'Semana passada'];

    public function show(Request $request, PeriodReport $reports): View
    {
        $companies = Company::query()->where('active', true)->orderByRaw("CASE WHEN person_type = 'pf' THEN 0 ELSE 1 END")->orderBy('name')->get();
        $company = $companies->firstWhere('id', (string) $request->query('empresa')) ?? $companies->first();
        $per = array_key_exists((string) $request->query('periodo'), self::PERIODS) ? (string) $request->query('periodo') : 'mes';
        $kind = str_starts_with($per, 'mes') ? 'mensal' : 'semanal';
        $today = CarbonImmutable::today();
        [$from, $to] = str_ends_with($per, 'passado') || str_ends_with($per, 'passada') ? PeriodReport::lastPeriod($kind, $today) : PeriodReport::currentPeriod($kind, $today);
        if ($kind === 'mensal' && ! str_ends_with($per, 'passado')) {
            $to = $from->endOfMonth()->startOfDay();   // mês atual inteiro (contas a vencer também aparecem)
        }
        $facts = $company ? $reports->facts($company, $kind, $from, $to) : null;

        return view('app.summary', ['companies' => $companies, 'company' => $company, 'per' => $per, 'facts' => $facts,
            'insights' => $facts ? $reports->insights($facts) : []]);
    }
}
