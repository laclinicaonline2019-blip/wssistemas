<?php

namespace App\Modules\Payments\Providers;

final class ChargeRequest
{
    /** @param  array{wallet_id: string, type: string, value: int}|null  $split */
    public function __construct(
        public readonly string $reference,
        public readonly int $amountCents,
        public readonly string $billingType,
        public readonly string $dueDate,
        public readonly string $description,
        public readonly ?string $customerId = null,
        public readonly ?array $split = null,
        public readonly int $maxInstallments = 1,
    ) {}
}
