<?php

namespace App\Console\Commands;

use App\Core\Access\PermissionRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Services\CompanyProvisioningService;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'aivexa:permissions:sync {--roles : Também cria perfis padrão ausentes e atualiza perfis protegidos em todas as empresas}';

    protected $description = 'Sincroniza o catálogo de permissões (config/permissions.php) com o banco';

    public function handle(PermissionRegistry $registry, TenantContext $context, CompanyProvisioningService $provisioning): int
    {
        $stats = $registry->sync();
        $this->info("Permissões: {$stats['created']} criadas, {$stats['updated']} atualizadas, {$stats['removed']} removidas.");

        if ($this->option('roles')) {
            Company::query()->each(function (Company $company) use ($context, $provisioning) {
                $context->runFor($company->id, fn () => $provisioning->createDefaultRoles());
                $this->line("  · perfis sincronizados: {$company->trade_name}");
            });
        }

        return self::SUCCESS;
    }
}
