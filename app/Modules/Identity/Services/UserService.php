<?php

namespace App\Modules\Identity\Services;

use App\Core\Access\PermissionService;
use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Services\PlanLimitService;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessGuard $guard,
        private readonly PermissionService $permissions,
        private readonly PlanLimitService $limits,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, password: string, roles?: list<array{role_id: string, branch_id?: string|null}>}  $data
     */
    public function create(User $actor, array $data): User
    {
        $assignments = $data['roles'] ?? [];

        if ($assignments === [] && $actor->allowedBranchIds() !== null) {
            throw new BusinessRuleViolation('Informe ao menos um perfil em uma filial sob sua gestão.');
        }

        return DB::transaction(function () use ($actor, $data, $assignments) {
            $this->limits->ensureCanAdd($this->context->companyId(), 'max_users', fn () => User::query()->count());

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'must_change_password' => true,
            ]);
            $user->company_id = $this->context->companyId();
            $user->save();

            if ($assignments !== []) {
                $this->applyRoles($actor, $user, $assignments, isNew: true);
            }

            return $user;
        });
    }

    /** @param  array{name?: string, email?: string, phone?: string|null, password?: string|null}  $data */
    public function update(User $actor, User $target, array $data): User
    {
        $this->guard->ensureCanManageUser($actor, $target);

        $fill = array_intersect_key($data, array_flip(['name', 'email', 'phone']));

        if (! empty($data['password'])) {
            $fill['password'] = $data['password'];
            // Senha definida por outra pessoa deve ser trocada no próximo acesso.
            $fill['must_change_password'] = $actor->isNot($target);
        }

        $target->update($fill);

        if (isset($fill['password']) && $actor->isNot($target)) {
            $target->tokens()->delete();
            $this->audit->record('user.password_reset_by_admin', $target);
        }

        return $target;
    }

    public function block(User $actor, User $target): User
    {
        $this->ensureNotSelf($actor, $target);
        $this->guard->ensureCanManageUser($actor, $target);
        $this->ensureNotLastAdmin($target);

        DB::transaction(function () use ($target) {
            $target->update(['status' => User::STATUS_BLOCKED]);
            $target->tokens()->delete();
            DB::table('sessions')->where('user_id', $target->id)->delete();
        });

        $this->audit->record('user.blocked', $target);

        return $target;
    }

    public function unblock(User $actor, User $target): User
    {
        $this->ensureNotSelf($actor, $target);
        $this->guard->ensureCanManageUser($actor, $target);

        $target->forceFill(['status' => User::STATUS_ACTIVE, 'locked_until' => null, 'failed_login_attempts' => 0])->save();
        $this->audit->record('user.unblocked', $target);

        return $target;
    }

    /**
     * Substitui os vínculos do usuário.
     *
     * @param  list<array{role_id: string, branch_id?: string|null}>  $assignments
     */
    public function syncRoles(User $actor, User $target, array $assignments): User
    {
        $this->ensureNotSelf($actor, $target);
        $this->guard->ensureCanManageUser($actor, $target);

        DB::transaction(function () use ($actor, $target, $assignments) {
            // Trava os vínculos do alvo para serializar alterações concorrentes.
            RoleAssignment::query()->where('user_id', $target->id)->lockForUpdate()->get();
            $this->applyRoles($actor, $target, $assignments, isNew: false);
        });

        return $target;
    }

    /** @param  list<array{role_id: string, branch_id?: string|null}>  $assignments */
    private function applyRoles(User $actor, User $target, array $assignments, bool $isNew): void
    {
        $desired = collect($assignments)
            ->map(fn ($a) => ['role_id' => $a['role_id'], 'branch_id' => $a['branch_id'] ?? null])
            ->unique(fn ($a) => $a['role_id'].'|'.$a['branch_id'])
            ->values();

        $roles = Role::query()->with('permissions')->whereIn('id', $desired->pluck('role_id'))->get()->keyBy('id');
        $branchIds = $desired->pluck('branch_id')->filter()->unique();
        $validBranches = Branch::query()->whereIn('id', $branchIds)->pluck('id')->all();

        foreach ($desired as $a) {
            if (! $roles->has($a['role_id']) || ($a['branch_id'] !== null && ! in_array($a['branch_id'], $validBranches, true))) {
                throw new BusinessRuleViolation('Perfil ou filial inválidos.');
            }
        }

        $current = $isNew ? collect() : RoleAssignment::query()->with('role.permissions')->where('user_id', $target->id)->get();
        $key = fn ($a) => $a['role_id'].'|'.($a['branch_id'] ?? '');

        $toAdd = $desired->reject(fn ($a) => $current->contains(fn ($c) => $key($c->only('role_id', 'branch_id')) === $key($a)));
        $toRemove = $current->reject(fn ($c) => $desired->contains(fn ($a) => $key($a) === $key($c->only('role_id', 'branch_id'))));

        foreach ($toAdd as $a) {
            $this->ensureCanAssign($actor, $roles[$a['role_id']], $a['branch_id']);
        }

        foreach ($toRemove as $c) {
            $this->ensureCanAssign($actor, $c->role, $c->branch_id);
        }

        foreach ($toRemove as $c) {
            if ($c->role->key === 'admin_empresa' && $c->branch_id === null) {
                $this->ensureNotLastAdmin($target);
            }
            $c->delete();
        }

        foreach ($toAdd as $a) {
            RoleAssignment::create([...$a, 'user_id' => $target->id, 'assigned_by' => $actor->id]);
        }

        $this->permissions->flush($target);
    }

    private function ensureCanAssign(User $actor, Role $role, ?string $branchId): void
    {
        $canAssign = $branchId === null
            ? $this->guard->hasCompanyWide($actor, 'usuario.perfis')
            : $this->permissions->userHas($actor, 'usuario.perfis', $branchId);

        if (! $canAssign) {
            $this->guard->deny('privilege_escalation', ['role' => $role->key, 'branch_id' => $branchId]);
        }

        $this->guard->ensureSubsetOfActor($actor, $role->permissionKeys(), $branchId);
    }

    private function ensureNotSelf(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            $this->guard->deny('self_change');
        }
    }

    /** A empresa deve manter ao menos um administrador ativo. */
    private function ensureNotLastAdmin(User $target): void
    {
        $adminRoleId = Role::query()->where('key', 'admin_empresa')->value('id');

        $isAdmin = RoleAssignment::query()->where('user_id', $target->id)->where('role_id', $adminRoleId)->whereNull('branch_id')->exists();

        if (! $isAdmin) {
            return;
        }

        $otherActiveAdmins = RoleAssignment::query()
            ->where('role_id', $adminRoleId)->whereNull('branch_id')->where('user_id', '!=', $target->id)
            ->whereHas('user', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->count();

        if ($otherActiveAdmins === 0) {
            throw new BusinessRuleViolation('A empresa precisa manter ao menos um administrador ativo.', 'last_admin');
        }
    }
}
