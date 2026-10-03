<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Platform\Models\Company;
use App\Modules\Security\Services\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Aplica a política de retenção de cada clínica e limpa dados técnicos da plataforma. */
class RetentionRunCommand extends Command
{
    protected $signature = 'aivexa:retention:run';

    protected $description = 'Retenção de dados operacionais (LGPD) — nunca toca dados clínicos, financeiros ou a auditoria';

    public function handle(TenantContext $context, RetentionService $retention): int
    {
        $total = 0;
        foreach ($context->runAsSystem(fn () => Company::query()->get()) as $company) {
            $total += array_sum($context->runFor($company->id, fn () => $retention->apply($company)));
        }
        // Plataforma: conteúdo bruto de webhooks de assinatura.
        $total += DB::table('platform_webhook_events')->where('created_at', '<', now()->subDays(180))->whereNotNull('payload')->update(['payload' => null]);
        $this->info("Registros tratados: {$total}.");

        return self::SUCCESS;
    }
}
