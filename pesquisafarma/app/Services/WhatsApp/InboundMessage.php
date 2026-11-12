<?php

namespace App\Services\WhatsApp;

/** Mensagem recebida, normalizada a partir do formato de cada provedor. */
final class InboundMessage
{
    public function __construct(
        public readonly string $phone,
        public readonly string $type = 'text', // text | image | document | audio
        public readonly ?string $text = null,
        public readonly ?string $senderName = null,
        public readonly ?string $providerId = null,
        public readonly ?string $mediaBytes = null,
        public readonly ?string $mime = null,
    ) {}

    public function hasMedia(): bool
    {
        return $this->mediaBytes !== null && $this->mediaBytes !== '';
    }
}
