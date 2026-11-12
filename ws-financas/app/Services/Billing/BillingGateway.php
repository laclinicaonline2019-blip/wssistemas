<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Subscription;
use Illuminate\Http\Request;

interface BillingGateway
{
    public function name(): string;

    /** Cria/atualiza o cliente e a assinatura recorrente no gateway; devolve [customerId, subscriptionId]. */
    public function createSubscription(Account $account, Subscription $sub, string $nextDueDate): array;

    public function updateSubscription(Subscription $sub, int $priceCents, string $cycle): void;

    public function cancelSubscription(Subscription $sub): void;

    /** @return list<array> pagamentos normalizados (ver AsaasGateway::normalize) */
    public function listPayments(Subscription $sub): array;

    public function fetchPayment(string $paymentId): ?array;

    public function verifyWebhook(Request $request): bool;
}
