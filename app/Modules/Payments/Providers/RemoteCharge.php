<?php

namespace App\Modules\Payments\Providers;

use Carbon\CarbonImmutable;

/** Situação da cobrança segundo a API do gateway (fonte de verdade). */
final class RemoteCharge
{
    /** @param  string  $status  pending, paid, overdue, cancelled, refunded, failed, review */
    public function __construct(
        public readonly string $status,
        public readonly ?int $paidCents = null,
        public readonly ?int $netCents = null,
        public readonly ?CarbonImmutable $paidAt = null,
        public readonly ?string $method = null,
        public readonly ?string $detail = null,
    ) {}
}
