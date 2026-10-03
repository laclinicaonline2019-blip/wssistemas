<?php

namespace App\Modules\Billing\Gateways;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * MOCK (homologação): nada é cobrado. A página "pagar" do MOCK marca a cobrança como paga
 * (só fora de produção) para testar o fluxo completo — confirmação pelo mesmo caminho do gateway real.
 */
class MockBillingGateway implements BillingGateway
{
    public function name(): string
    {
        return 'mock';
    }

    public function createCharge(Subscription $subscription, SubscriptionInvoice $invoice): array
    {
        $id = 'mock_'.Str::lower((string) Str::ulid());

        return ['id' => $id, 'url' => route('billing.mock_pay', $id)];
    }

    public function fetchCharge(string $chargeId): array
    {
        $paid = Cache::get('billing-mock-paid:'.$chargeId);

        return ['paid' => $paid !== null, 'amount_cents' => $paid, 'status' => $paid !== null ? 'RECEIVED' : 'PENDING'];
    }

    public function cancelCharge(string $chargeId): void {}

    public static function simulatePayment(string $chargeId, int $amountCents): void
    {
        Cache::put('billing-mock-paid:'.$chargeId, $amountCents, now()->addDays(30));
    }

    public function verifyWebhook(Request $request): bool
    {
        return false; // MOCK não recebe webhook externo
    }

    public function parseWebhook(Request $request): array
    {
        return ['event_id' => null, 'event' => null, 'charge_id' => null];
    }
}
