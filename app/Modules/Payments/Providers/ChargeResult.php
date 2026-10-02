<?php

namespace App\Modules\Payments\Providers;

final class ChargeResult
{
    public function __construct(
        public readonly string $providerChargeId,
        public readonly ?string $paymentUrl,
        public readonly ?string $pixPayload = null,
        public readonly ?string $pixQrImage = null,
    ) {}
}
