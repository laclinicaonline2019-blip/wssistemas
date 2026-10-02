<?php

namespace App\Modules\Payments\Providers;

final class WebhookEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly ?string $type,
        public readonly ?string $providerChargeId,
        public readonly array $payload,
    ) {}
}
