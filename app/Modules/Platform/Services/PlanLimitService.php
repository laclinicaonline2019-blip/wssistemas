<?php

namespace App\Modules\Platform\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Platform\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Limites do plano SaaS. Deve ser chamado DENTRO de uma transação: trava a
 * linha da empresa para que criações concorrentes não ultrapassem o limite.
 */
class PlanLimitService
{
    private const LABELS = [
        'max_users' => 'usuários',
        'max_branches' => 'filiais',
        'max_doctors' => 'médicos',
    ];

    public function ensureCanAdd(string $companyId, string $limitKey, callable $currentCount): void
    {
        /** @var Company $company */
        $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $limit = $company->plan?->limit($limitKey);

        if ($limit === null) {
            return; // ilimitado
        }

        if ($currentCount() >= (int) $limit) {
            throw new BusinessRuleViolation(
                sprintf('Limite do plano atingido: máximo de %d %s. Faça upgrade do plano.', $limit, self::LABELS[$limitKey] ?? $limitKey),
                'plan_limit_reached',
            );
        }
    }

    public function usage(Company $company): array
    {
        return [
            'max_users' => ['used' => DB::table('users')->where('company_id', $company->id)->whereNull('deleted_at')->count(), 'limit' => $company->plan?->limit('max_users')],
            'max_branches' => ['used' => DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->count(), 'limit' => $company->plan?->limit('max_branches')],
        ];
    }
}
