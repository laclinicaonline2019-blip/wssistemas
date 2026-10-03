<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\Request;

interface WhatsAppProvider
{
    /** true = API oficial: mensagem iniciada pela clínica só por modelo aprovado e janela de 24 h. */
    public function usesTemplates(): bool;

    /** Mensagem iniciada pela clínica: só com modelo aprovado. Retorna o ID da mensagem no provedor. */
    public function sendTemplate(MessagingChannel $channel, string $to, string $name, string $language, array $bodyParams, array $buttonPayloads = []): string;

    /** Texto livre: só dentro da janela de 24 h após a última mensagem do paciente. */
    public function sendText(MessagingChannel $channel, string $to, string $text): string;

    public function verifySignature(MessagingChannel $channel, Request $request): bool;

    /** @return list<InboundEvent> */
    public function parseWebhook(array $payload): array;
}
