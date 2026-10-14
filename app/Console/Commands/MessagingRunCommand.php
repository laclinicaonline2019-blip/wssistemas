<?php

namespace App\Console\Commands;

use App\Modules\Messaging\Services\AppointmentNotifier;
use App\Modules\Messaging\Services\MessageService;
use Illuminate\Console\Command;

/** Lembretes de consulta (24 h, 2 h ou personalizados) e reenvio de mensagens pendentes. */
class MessagingRunCommand extends Command
{
    protected $signature = 'wsfacilitsaude:messaging:run';

    /** Nomes antigos (WSCLINIC e AivexaClínica) mantidos para crons e scripts já configurados. */
    protected $aliases = ['wsclinic:messaging:run', 'aivexa:messaging:run'];

    protected $description = 'Gera os lembretes de consulta e reenvia mensagens pendentes (WhatsApp/e-mail)';

    public function handle(AppointmentNotifier $notifier, MessageService $messages): int
    {
        $reminders = $notifier->sendReminders();
        $retried = $messages->retryDue();
        $this->info("Lembretes enfileirados: {$reminders}. Mensagens processadas: {$retried}.");

        return self::SUCCESS;
    }
}
