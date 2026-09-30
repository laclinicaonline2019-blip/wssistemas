<?php

namespace App\Core\Access;

use App\Modules\Identity\Models\Permission;
use Illuminate\Support\Facades\DB;

/** Leitura do catálogo config/permissions.php e sincronização com o banco. */
class PermissionRegistry
{
    /** @return array<string, array{module: string, description: string, scope: string}> */
    public function all(): array
    {
        $out = [];

        foreach (config('permissions.modules', []) as $module => $definition) {
            foreach ($definition['permissions'] as $key => $description) {
                $out[$key] = [
                    'module' => $module,
                    'description' => $description,
                    'scope' => $definition['scope'] ?? 'tenant',
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function tenantKeys(): array
    {
        return array_keys(array_filter($this->all(), fn ($p) => $p['scope'] === 'tenant'));
    }

    /** @return list<string> */
    public function platformKeys(): array
    {
        return array_keys(array_filter($this->all(), fn ($p) => $p['scope'] === 'platform'));
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @return array<string, array{label: string, permissions: array<string, string>, scope?: string}> */
    public function modules(string $scope = 'tenant'): array
    {
        return array_filter(
            config('permissions.modules', []),
            fn ($m) => ($m['scope'] ?? 'tenant') === $scope,
        );
    }

    /** @return list<string> */
    public function resolveTemplate(array $permissions): array
    {
        return in_array('*', $permissions, true) ? $this->tenantKeys() : array_values($permissions);
    }

    /**
     * Sincroniza o catálogo: cria/atualiza permissões e remove as que saíram
     * do catálogo (desvinculando dos perfis).
     *
     * @return array{created: int, updated: int, removed: int}
     */
    public function sync(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'removed' => 0];

        DB::transaction(function () use (&$stats) {
            foreach ($this->all() as $key => $data) {
                $permission = Permission::firstOrNew(['key' => $key]);
                $permission->fill($data);

                if (! $permission->exists) {
                    $stats['created']++;
                } elseif ($permission->isDirty()) {
                    $stats['updated']++;
                }

                $permission->save();
            }

            $stats['removed'] = Permission::whereNotIn('key', array_keys($this->all()))->delete();
        });

        app(PermissionService::class)->flush();

        return $stats;
    }
}
