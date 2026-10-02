<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentService;
use Illuminate\Console\Command;

/** Consulta nos gateways as cobranças em aberto (cobre webhooks perdidos). Agendado a cada 10 minutos. */
class SyncPaymentsCommand extends Command
{
    protected $signature = 'aivexa:payments:sync {--limit=100}';

    protected $description = 'Sincroniza com os gateways as cobranças online em aberto';

    public function handle(PaymentService $payments): int
    {
        $count = $payments->syncOpenCharges((int) $this->option('limit'));
        $this->info("{$count} cobrança(s) consultada(s).");

        return self::SUCCESS;
    }
}
