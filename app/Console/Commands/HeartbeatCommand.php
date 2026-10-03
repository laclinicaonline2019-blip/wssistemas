<?php

namespace App\Console\Commands;

use App\Core\Health\ReadinessChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Sinal de vida do agendador (cron): a verificação de prontidão alerta se parar. */
class HeartbeatCommand extends Command
{
    protected $signature = 'aivexa:heartbeat';

    protected $description = 'Registra que o agendador (cron) está rodando';

    public function handle(): int
    {
        Cache::forever(ReadinessChecker::HEARTBEAT_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
