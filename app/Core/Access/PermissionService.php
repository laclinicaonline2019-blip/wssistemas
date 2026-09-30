<?php

namespace App\Core\Access;

use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolução de permissões efetivas (RBAC com escopo de filial).
 *
 * - Super Admin: somente permissões de plataforma (menor privilégio: não
 *   acessa dados clínicos das empresas).
 * - Usuário de clínica: união das permissões dos perfis atribuídos com
 *   branch_id NULL (empresa toda) ou igual à filial avaliada.
 */
class PermissionService
{
    /** @var array<string, array<string, list<string|null>>> user => permission => branch ids (null = todas) */
    private array $cache = [];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly TenantContext $context,
    ) {}

    public function userHas(User $user, string $permission, ?string $branchId = null): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->is_super_admin) {
            return in_array($permission, $this->registry->platformKeys(), true);
        }

        $branchId ??= $this->context->branchId();
        $grants = $this->grants($user)[$permission] ?? [];

        if (in_array(null, $grants, true)) {
            return true;
        }

        return $branchId !== null && in_array($branchId, $grants, true);
    }

    /**
     * Permissões efetivas para exibição/uso no frontend (o backend sempre revalida).
     *
     * @return list<string>
     */
    public function effective(User $user, ?string $branchId = null): array
    {
        if ($user->is_super_admin) {
            return $this->registry->platformKeys();
        }

        $keys = array_keys(array_filter(
            $this->grants($user),
            fn ($branches) => in_array(null, $branches, true) || ($branchId !== null && in_array($branchId, $branches, true)),
        ));
        sort($keys);

        return $keys;
    }

    /**
     * Filiais acessíveis. null = todas as filiais da empresa.
     *
     * @return list<string>|null
     */
    public function allowedBranchIds(User $user): ?array
    {
        if ($user->is_super_admin) {
            return [];
        }

        $branches = DB::table('user_role_assignments')
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->getKey())
            ->pluck('branch_id')
            ->all();

        if (in_array(null, $branches, true)) {
            return null;
        }

        return array_values(array_unique($branches));
    }

    public function flush(?User $user = null): void
    {
        if ($user) {
            unset($this->cache[$user->getKey()]);
        } else {
            $this->cache = [];
        }
    }

    /** @return array<string, list<string|null>> */
    private function grants(User $user): array
    {
        return $this->cache[$user->getKey()] ??= $this->loadGrants($user);
    }

    /** @return array<string, list<string|null>> */
    private function loadGrants(User $user): array
    {
        // Filtro explícito por company_id: independe do contexto de tenant.
        $rows = DB::table('user_role_assignments as ura')
            ->join('roles as r', function ($join) {
                $join->on('r.id', '=', 'ura.role_id')->on('r.company_id', '=', 'ura.company_id');
            })
            ->join('role_permission as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ura.company_id', $user->company_id)
            ->where('ura.user_id', $user->getKey())
            ->where('p.scope', 'tenant')
            ->select('p.key', 'ura.branch_id')
            ->get();

        $grants = [];
        foreach ($rows as $row) {
            $grants[$row->key][] = $row->branch_id;
        }

        return $grants;
    }
}
