<?php

namespace App\Modules\Identity\Services;

use App\Core\Access\PermissionRegistry;
use App\Core\Access\PermissionService;
use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleService
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly PermissionRegistry $registry,
        private readonly PermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{name: string, description?: string|null, permissions: list<string>}  $data */
    public function create(User $actor, array $data): Role
    {
        $this->ensureCanManage($actor);
        $keys = $this->validKeys($data['permissions']);
        $this->guard->ensureSubsetOfActor($actor, $keys, null);

        return DB::transaction(function () use ($data, $keys) {
            $role = Role::create([
                'key' => $this->uniqueKey($data['name']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $this->syncPermissions($role, $keys, []);

            return $role;
        });
    }

    /** @param  array{name?: string, description?: string|null, permissions?: list<string>}  $data */
    public function update(User $actor, Role $role, array $data): Role
    {
        $this->ensureCanManage($actor);

        if ($role->is_locked) {
            throw new BusinessRuleViolation('Este perfil é protegido e não pode ser alterado.', 'role_locked');
        }

        return DB::transaction(function () use ($actor, $role, $data) {
            $role->update(array_intersect_key($data, array_flip(['name', 'description'])));

            if (array_key_exists('permissions', $data)) {
                $keys = $this->validKeys($data['permissions']);
                $current = $role->permissionKeys();
                // Só é possível ACRESCENTAR permissões que o próprio ator possui.
                $this->guard->ensureSubsetOfActor($actor, array_values(array_diff($keys, $current)), null);
                $this->syncPermissions($role, $keys, $current);
            }

            return $role->refresh();
        });
    }

    public function delete(User $actor, Role $role): void
    {
        $this->ensureCanManage($actor);

        if ($role->is_system) {
            throw new BusinessRuleViolation('Perfis padrão do sistema não podem ser excluídos.');
        }

        if ($role->assignments()->exists()) {
            throw new BusinessRuleViolation('Remova o perfil dos usuários antes de excluí-lo.');
        }

        $role->delete();
    }

    /** @param  list<string>  $keys  @param  list<string>  $previous */
    private function syncPermissions(Role $role, array $keys, array $previous): void
    {
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id')->all();
        $role->permissions()->sync($ids);
        $role->unsetRelation('permissions');

        $added = array_values(array_diff($keys, $previous));
        $removed = array_values(array_diff($previous, $keys));

        if ($added || $removed) {
            $this->audit->record('role.permissions_changed', $role, old: ['removed' => $removed], new: ['added' => $added]);
        }

        $this->permissions->flush();
    }

    private function ensureCanManage(User $actor): void
    {
        if (! $this->guard->hasCompanyWide($actor, 'perfil.gerenciar')) {
            $this->guard->deny('company_wide_required', ['permission' => 'perfil.gerenciar']);
        }
    }

    /** @return list<string> */
    private function validKeys(array $keys): array
    {
        $tenant = $this->registry->tenantKeys();
        $invalid = array_diff($keys, $tenant);

        if ($invalid !== []) {
            throw new BusinessRuleViolation('Permissões inválidas: '.implode(', ', $invalid));
        }

        return array_values(array_unique($keys));
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_') ?: 'perfil', 50, '');
        $key = $base;
        $i = 2;

        while (Role::query()->where('key', $key)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }
}
