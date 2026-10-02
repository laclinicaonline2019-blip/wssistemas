<?php

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Services\AiReceptionist;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Resposta da IA a uma mensagem do WhatsApp. Roda DEPOIS de devolver o 200 ao webhook
 * (dispatchAfterResponse): a Meta recebe a confirmação na hora e a chamada ao modelo não
 * depende de worker de fila — funciona na hospedagem compartilhada (só cron).
 */
class AiReply
{
    use Dispatchable;

    public function __construct(
        public readonly string $companyId,
        public readonly string $threadId,
        public readonly string $messageId,
    ) {}

    public function handle(AiReceptionist $receptionist): void
    {
        ignore_user_abort(true);
        @set_time_limit(240);
        $receptionist->respond($this->companyId, $this->threadId, $this->messageId);
    }
}
