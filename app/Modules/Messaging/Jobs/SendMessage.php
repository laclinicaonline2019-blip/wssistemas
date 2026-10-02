<?php

namespace App\Modules\Messaging\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\Messaging\Services\MessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Envio de uma mensagem da caixa de saída (fila "database" + cron na HostGator). */
class SendMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1; // novas tentativas são controladas pela própria mensagem (next_attempt_at)

    public function __construct(public readonly string $companyId, public readonly string $messageId) {}

    public function handle(TenantContext $context, MessageService $messages): void
    {
        $context->runFor($this->companyId, fn () => $messages->deliverById($this->messageId));
    }
}
