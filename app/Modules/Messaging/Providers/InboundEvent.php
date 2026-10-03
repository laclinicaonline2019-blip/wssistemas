<?php

namespace App\Modules\Messaging\Providers;

/** Evento recebido do WhatsApp: mensagem do paciente ou atualização de status de uma mensagem enviada. */
final class InboundEvent
{
    public function __construct(
        public readonly string $type,            // message | status
        public readonly string $providerId,      // wamid
        public readonly ?string $from = null,    // telefone do paciente (wa_id)
        public readonly ?string $contactName = null,
        public readonly ?string $text = null,
        public readonly ?string $buttonPayload = null,
        public readonly ?string $status = null,  // sent | delivered | read | failed
        public readonly ?string $error = null,
        public readonly ?int $timestamp = null,
        public readonly ?string $phoneNumberId = null,
        /** Mídia recebida: {kind: audio|image|document, id?, url?, data?(base64), mime?, filename?, caption?} */
        public readonly ?array $media = null,
    ) {}
}
