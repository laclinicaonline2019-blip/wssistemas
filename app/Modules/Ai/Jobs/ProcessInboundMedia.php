<?php

namespace App\Modules\Ai\Jobs;

use App\Modules\Ai\Services\Media\MediaPipeline;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Processa áudio/imagem/PDF recebido no WhatsApp depois do 200 ao webhook (dispatchAfterResponse),
 * no mesmo processo — o conteúdo em base64 de alguns provedores não precisa ir para o banco.
 */
class ProcessInboundMedia
{
    use Dispatchable;

    public function __construct(public readonly string $companyId, public readonly string $messageId, public readonly array $media) {}

    public function handle(MediaPipeline $pipeline): void
    {
        ignore_user_abort(true);
        @set_time_limit(300);
        $pipeline->processInbound($this->companyId, $this->messageId, $this->media);
    }
}
