<?php

namespace App\Http\Concerns;

use App\Core\Support\Money;
use App\Models\Company;
use App\Models\CostCenter;
use Illuminate\Http\Request;

/** Campos de "regras de funcionário" (aprovação, limite, centros de custo e — para número avulso — empresas). */
trait EmployeeRulesInput
{
    protected function employeeRules(Request $request, bool $withCompanies = false): array
    {
        $request->validate(['requires_approval' => ['boolean'], 'approval_limit' => ['nullable', 'string', 'max:20'], 'all_cost_centers' => ['boolean'],
            'cost_centers' => ['array'], 'cost_centers.*' => ['string'], 'all_rule_companies' => ['boolean'], 'rule_companies' => ['array'], 'rule_companies.*' => ['string']]);
        $limit = trim((string) $request->input('approval_limit', ''));
        $centers = $request->boolean('all_cost_centers', true) ? null
            : CostCenter::query()->whereIn('id', (array) $request->input('cost_centers', []))->pluck('id')->all();
        $out = [
            'requires_approval' => $request->boolean('requires_approval'),
            'approval_limit_cents' => $limit === '' ? null : Money::parse($limit),
            'cost_center_ids' => $centers ?: null,   // nenhum marcado = sem restrição
        ];
        if ($withCompanies) {
            $ids = $request->boolean('all_rule_companies', true) ? null : Company::query()->whereIn('id', (array) $request->input('rule_companies', []))->pluck('id')->all();
            $out['company_ids'] = $ids ?: null;
        }

        return $out;
    }
}
