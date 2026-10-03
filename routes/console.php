<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tarefas agendadas. No cPanel (HostGator) configure UM cron a cada minuto:
|   * * * * * /usr/local/bin/php /home/CONTA/aivexa/artisan schedule:run >> /dev/null 2>&1
| Os módulos futuros registram aqui lembretes, cobranças, conciliação e backup.
*/

// Hospedagem compartilhada não permite processos permanentes: o worker de fila roda
// a cada minuto até esvaziar a fila (limite de 50 s para não sobrepor execuções).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --backoff=30')
    ->everyMinute()->withoutOverlapping(5)->when(fn () => config('queue.default') === 'database');
Schedule::command('aivexa:audit:verify')->dailyAt('03:10')->onOneServer();

Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
// Pagamentos online: consulta periódica cobre webhooks perdidos (gateway fora do ar, erro de rede).
Schedule::command('aivexa:payments:sync')->everyTenMinutes()->withoutOverlapping(15)->onOneServer();
// WhatsApp/e-mail (Fase 11): lembretes de consulta e reenvio de mensagens pendentes.
Schedule::command('aivexa:messaging:run')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
// Conciliação bancária (Fase 14): extratos das contas conectadas por Open Finance.
Schedule::command('aivexa:bank:sync')->dailyAt('06:20')->withoutOverlapping(60)->onOneServer();
