<?php

namespace App\Modules\Billing\Gateways;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use Illuminate\Http\Request;

/** Cobrança da assinatura (conta da plataforma). Pagamento só é confirmado consultando a API. */
interface BillingGateway
{
    public function name(): string;

    /** @return array{id: string, url: ?string} */
    public function createCharge(Subscription $subscription, SubscriptionInvoice $invoice): array;

    /** @return array{paid: bool, amount_cents: ?int, status: string} */
    public function fetchCharge(string $chargeId): array;

    public function cancelCharge(string $chargeId): void;

    public function verifyWebhook(Request $request): bool;

    /** @return array{event_id: ?string, event: ?string, charge_id: ?string} */
    public function parseWebhook(Request $request): array;
}
