<?php

namespace App\Modules\Organization\Services;

use App\Core\Access\PermissionService;
use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Services\PlanLimitService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanLimitService $limits,
        private readonly PermissionService $permissions,
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $actor, array $data): Branch
    {
        $this->requireCompanyWide($actor, 'filial.criar');

        return DB::transaction(function () use ($data) {
            $this->limits->ensureCanAdd($this->context->companyId(), 'max_branches', fn () => Branch::query()->count());

            // A matriz é única e definida no provisionamento.
            unset($data['is_headquarters']);

            return Branch::create($data);
        });
    }

    public function update(User $actor, Branch $branch, array $data): Branch
    {
        if (! $this->permissions->userHas($actor, 'filial.editar', $branch->id)) {
            throw new AuthorizationException('Sem permissão para editar esta filial.');
        }

        unset($data['is_headquarters'], $data['status']);
        $branch->update($data);

        return $branch;
    }

    public function setStatus(User $actor, Branch $branch, string $status): Branch
    {
        $this->requireCompanyWide($actor, 'filial.desativar');

        if ($status === 'inactive' && $branch->is_headquarters) {
            throw new BusinessRuleViolation('A matriz não pode ser desativada.');
        }

        $branch->update(['status' => $status]);

        return $branch;
    }

    private function requireCompanyWide(User $actor, string $permission): void
    {
        if (! $this->guard->hasCompanyWide($actor, $permission)) {
            $this->audit->record('access.denied', result: 'denied', metadata: ['permission' => $permission, 'scope' => 'company']);
            throw new AuthorizationException('Esta ação exige permissão em toda a empresa.');
        }
    }
}
