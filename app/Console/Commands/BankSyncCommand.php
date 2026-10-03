<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Services\BankSync;
use Illuminate\Console\Command;
use Throwable;

/** Lê os extratos das contas conectadas por Open Finance (todas as clínicas). */
class BankSyncCommand extends Command
{
    protected $signature = 'aivexa:bank:sync';

    protected $description = 'Sincroniza extratos bancários via Open Finance (agregador)';

    public function handle(TenantContext $context, BankSync $sync): int
    {
        $accounts = $context->runAsSystem(fn () => BankAccount::query()->withoutGlobalScopes()->where('sync_provider', '!=', 'none')->where('is_active', true)->get(['id', 'company_id']));
        $ok = 0;
        foreach ($accounts as $a) {
            try {
                $context->runFor($a->company_id, fn () => $sync->sync(null, BankAccount::query()->findOrFail($a->id)));
                $ok++;
            } catch (Throwable $e) {
                $this->warn("Conta {$a->id}: ".$e->getMessage());
            }
        }
        $this->info("Contas sincronizadas: {$ok}/{$accounts->count()}.");

        return self::SUCCESS;
    }
}
