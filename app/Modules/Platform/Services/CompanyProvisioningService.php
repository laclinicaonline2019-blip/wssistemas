<?php

namespace App\Modules\Platform\Services;

use App\Core\Access\PermissionRegistry;
use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provisionamento de uma nova clínica: empresa + matriz + perfis padrão +
 * administrador, tudo em uma única transação.
 */
class CompanyProvisioningService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{legal_name: string, trade_name: string, document: string, email?: string|null, phone?: string|null, saas_plan_id?: string|null, status?: string}  $company
     * @param  array{name: string, code?: string, city?: string|null, state?: string|null, timezone?: string}  $headquarters
     * @param  array{name: string, email: string, password: string, must_change_password?: bool}  $admin
     * @return array{company: Company, branch: Branch, admin: User}
     */
    public function provision(array $company, array $headquarters, array $admin): array
    {
        return DB::transaction(function () use ($company, $headquarters, $admin) {
            $plan = isset($company['saas_plan_id']) ? SaasPlan::find($company['saas_plan_id']) : null;

            $model = new Company([
                ...$company,
                'document' => preg_replace('/\D/', '', $company['document']),
                'slug' => $this->uniqueSlug($company['trade_name']),
                'status' => $company['status'] ?? Company::STATUS_TRIAL,
                'trial_ends_at' => ($company['status'] ?? Company::STATUS_TRIAL) === Company::STATUS_TRIAL
                    ? now()->addDays($plan?->trial_days ?: 14)
                    : null,
            ]);
            $model->save();

            return $this->context->runFor($model->id, function () use ($model, $headquarters, $admin) {
                $branch = Branch::create([
                    'name' => $headquarters['name'],
                    'code' => $headquarters['code'] ?? 'MATRIZ',
                    'is_headquarters' => true,
                    'city' => $headquarters['city'] ?? null,
                    'state' => $headquarters['state'] ?? null,
                    'timezone' => $headquarters['timezone'] ?? 'America/Sao_Paulo',
                ]);

                $roles = $this->createDefaultRoles();

                $user = new User([
                    'name' => $admin['name'],
                    'email' => $admin['email'],
                    'password' => $admin['password'],
                    'must_change_password' => $admin['must_change_password'] ?? false,
                ]);
                $user->company_id = $model->id;
                $user->save();

                RoleAssignment::create(['user_id' => $user->id, 'role_id' => $roles['admin_empresa']->id, 'branch_id' => null]);

                $this->audit->record('company.provisioned', $model, new: ['admin_user_id' => $user->id, 'branch_id' => $branch->id]);

                return ['company' => $model, 'branch' => $branch, 'admin' => $user];
            });
        });
    }

    /**
     * Cria (ou completa) os perfis padrão da empresa atual a partir dos templates.
     *
     * @return array<string, Role>
     */
    public function createDefaultRoles(): array
    {
        $permissionIds = Permission::query()->pluck('id', 'key');
        $roles = [];

        foreach (config('permissions.role_templates') as $key => $template) {
            $role = Role::query()->firstOrNew(['key' => $key]);

            if (! $role->exists) {
                $role->fill(['name' => $template['name'], 'description' => $template['description']]);
                $role->is_system = true;
                $role->is_locked = (bool) $template['locked'];
                $role->save();

                $keys = $this->registry->resolveTemplate($template['permissions']);
                $role->permissions()->sync($permissionIds->only($keys)->values()->all());
            } elseif ($role->is_locked) {
                // Perfis bloqueados (admin) acompanham o catálogo completo.
                $keys = $this->registry->resolveTemplate($template['permissions']);
                $role->permissions()->sync($permissionIds->only($keys)->values()->all());
            }

            $roles[$key] = $role;
        }

        return $roles;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'clinica';
        $slug = Str::limit($base, 60, '');
        $i = 2;

        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = Str::limit($base, 55, '').'-'.$i++;
        }

        return $slug;
    }
}
