<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Gateway simulado (desenvolvimento/homologação): nenhuma cobrança real; o pagamento é marcado manualmente. */
class MockGateway implements BillingGateway
{
    public function name(): string
    {
        return 'mock';
    }

    public function createSubscription(Account $account, Subscription $sub, string $nextDueDate): array
    {
        return ['mock_cus_'.Str::lower(Str::random(10)), 'mock_sub_'.Str::lower(Str::random(10))];
    }

    public function updateSubscription(Subscription $sub, int $priceCents, string $cycle): void {}

    public function cancelSubscription(Subscription $sub): void {}

    public function listPayments(Subscription $sub): array
    {
        return [];
    }

    public function fetchPayment(string $paymentId): ?array
    {
        return null;
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }
}
