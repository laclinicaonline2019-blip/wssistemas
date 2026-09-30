<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tarefas agendadas (cron: * * * * * php artisan schedule:run).
| Os módulos futuros registram aqui lembretes, cobranças, conciliação e backup.
*/

Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
