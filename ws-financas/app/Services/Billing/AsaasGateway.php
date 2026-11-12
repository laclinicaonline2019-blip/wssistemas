<?php

namespace App\Services\Billing;

use App\Core\Support\BusinessRule;
use App\Models\Account;
use App\Models\Subscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Asaas (API v3) — assinaturas recorrentes com cobrança "a escolher" (Pix, boleto ou cartão na página do Asaas).
 * Webhook autenticado pelo token `asaas-access-token`; o status de cada pagamento é sempre reconsultado na API.
 */
class AsaasGateway implements BillingGateway
{
    public function name(): string
    {
        return 'asaas';
    }

    public function createSubscription(Account $account, Subscription $sub, string $nextDueDate): array
    {
        $customer = $sub->gateway_customer_id ?: $this->customer($account);
        $r = $this->call(fn () => $this->client()->post('subscriptions', [
            'customer' => $customer, 'billingType' => 'UNDEFINED', 'value' => round($sub->price_cents / 100, 2), 'nextDueDate' => $nextDueDate,
            'cycle' => $sub->cycle === 'yearly' ? 'YEARLY' : 'MONTHLY', 'description' => 'WS Finanças em Dia — plano '.$sub->plan->name, 'externalReference' => $sub->id,
        ]), 'criar a assinatura');

        return [$customer, (string) $r['id']];
    }

    public function updateSubscription(Subscription $sub, int $priceCents, string $cycle): void
    {
        $this->call(fn () => $this->client()->post('subscriptions/'.rawurlencode((string) $sub->gateway_subscription_id), [
            'value' => round($priceCents / 100, 2), 'cycle' => $cycle === 'yearly' ? 'YEARLY' : 'MONTHLY', 'updatePendingPayments' => true,
        ]), 'alterar a assinatura');
    }

    public function cancelSubscription(Subscription $sub): void
    {
        if ($sub->gateway_subscription_id) {
            $this->call(fn () => $this->client()->delete('subscriptions/'.rawurlencode($sub->gateway_subscription_id)), 'cancelar a assinatura');
        }
    }

    public function listPayments(Subscription $sub): array
    {
        if (! $sub->gateway_subscription_id) {
            return [];
        }
        $r = $this->call(fn () => $this->client()->get('subscriptions/'.rawurlencode($sub->gateway_subscription_id).'/payments', ['limit' => 100]), 'listar cobranças');

        return array_map(self::normalize(...), $r['data'] ?? []);
    }

    public function fetchPayment(string $paymentId): ?array
    {
        $r = $this->call(fn () => $this->client()->get('payments/'.rawurlencode($paymentId)), 'consultar a cobrança');

        return isset($r['id']) ? self::normalize($r) : null;
    }

    public function verifyWebhook(Request $request): bool
    {
        $token = (string) config('services.asaas.webhook_token');

        return $token !== '' && hash_equals($token, (string) $request->header('asaas-access-token', ''));
    }

    /** Pagamento do Asaas → formato interno. */
    public static function normalize(array $p): array
    {
        $status = (string) ($p['status'] ?? '');

        return [
            'id' => (string) $p['id'],
            'subscription' => $p['subscription'] ?? null,
            'amount_cents' => (int) round(((float) ($p['value'] ?? 0)) * 100),
            'due_date' => (string) ($p['dueDate'] ?? now()->toDateString()),
            'status' => match ($status) {
                'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => 'paid',
                'OVERDUE' => 'overdue',
                'REFUNDED', 'REFUND_REQUESTED', 'CHARGEBACK_REQUESTED' => 'refunded',
                'DELETED' => 'canceled',
                default => 'pending',
            },
            'billing_type' => $p['billingType'] ?? null,
            'invoice_url' => $p['invoiceUrl'] ?? null,
            'paid_at' => $p['paymentDate'] ?? $p['clientPaymentDate'] ?? null,
        ];
    }

    private function customer(Account $account): string
    {
        $doc = preg_replace('/\D/', '', (string) $account->document);
        if ($doc === '') {
            throw new BusinessRule('Informe o CPF/CNPJ do cliente antes de assinar (exigido pelo Asaas).', 'documento');
        }
        $found = $this->call(fn () => $this->client()->get('customers', ['cpfCnpj' => $doc]), 'consultar o cliente');

        return $found['data'][0]['id'] ?? $this->call(fn () => $this->client()->post('customers', [
            'name' => $account->name, 'cpfCnpj' => $doc, 'email' => $account->email, 'externalReference' => $account->id,
        ]), 'cadastrar o cliente')['id'];
    }

    private function client(): PendingRequest
    {
        $key = (string) config('services.asaas.api_key');
        if ($key === '') {
            throw new BusinessRule('Cobrança não configurada (ASAAS_API_KEY).', 'billing');
        }

        return Http::baseUrl(config('services.asaas.sandbox') ? 'https://api-sandbox.asaas.com/v3/' : 'https://api.asaas.com/v3/')
            ->withHeaders(['access_token' => $key, 'User-Agent' => 'WSFinancas'])->acceptJson()->asJson()->timeout(20);
    }

    private function call(callable $fn, string $what): array
    {
        try {
            $r = $fn();
        } catch (ConnectionException) {
            throw new BusinessRule('Sem conexão com o Asaas para '.$what.'. Tente novamente.', 'billing');
        }
        if (! $r->successful()) {
            $msg = collect($r->json('errors') ?? [])->pluck('description')->implode('; ') ?: 'HTTP '.$r->status();
            throw new BusinessRule('O Asaas recusou '.$what.': '.$msg, 'billing');
        }

        return $r->json() ?? [];
    }
}
