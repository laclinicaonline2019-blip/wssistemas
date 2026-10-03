<?php

namespace App\Console\Commands;

use App\Modules\Billing\Services\SubscriptionService;
use Illuminate\Console\Command;

/** Assinaturas: faturas de renovação, cobranças pendentes, conferência no gateway e régua de cobrança. */
class BillingRunCommand extends Command
{
    protected $signature = 'aivexa:billing:run';

    protected $description = 'Processa as assinaturas das clínicas (renovação, cobrança, atraso, bloqueio, cancelamento)';

    public function handle(SubscriptionService $service): int
    {
        $s = $service->run();
        $this->info("Faturas: {$s['issued']} · pagas: {$s['paid']} · em atraso: {$s['past_due']} · bloqueadas: {$s['suspended']} · encerradas: {$s['cancelled']}");

        return self::SUCCESS;
    }
}
