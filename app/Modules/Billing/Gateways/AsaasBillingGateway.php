<?php

namespace App\Modules\Billing\Gateways;

use App\Core\Support\BusinessRuleViolation;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * ASAAS da plataforma: cliente = CNPJ da clínica; cobrança com forma "a escolher" (PIX, boleto ou
 * cartão na página do ASAAS); webhook autenticado pelo token (asaas-access-token) e status
 * confirmado por GET /payments/{id}.
 */
class AsaasBillingGateway implements BillingGateway
{
    public function name(): string
    {
        return 'asaas';
    }

    public function createCharge(Subscription $subscription, SubscriptionInvoice $invoice): array
    {
        $customer = $subscription->gateway_customer_id ?: $this->customer($subscription);
        $r = $this->call(fn () => $this->client()->post('payments', [
            'customer' => $customer, 'billingType' => 'UNDEFINED', 'value' => round($invoice->amount_cents / 100, 2),
            'dueDate' => $invoice->due_date->toDateString(), 'description' => mb_substr($invoice->description, 0, 500), 'externalReference' => $invoice->id,
        ]), 'criar a cobrança');

        return ['id' => (string) $r['id'], 'url' => $r['invoiceUrl'] ?? null];
    }

    public function fetchCharge(string $chargeId): array
    {
        $r = $this->call(fn () => $this->client()->get('payments/'.rawurlencode($chargeId)), 'consultar a cobrança');
        $status = (string) ($r['status'] ?? '');

        return ['paid' => in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true), 'amount_cents' => isset($r['value']) ? (int) round($r['value'] * 100) : null, 'status' => $status];
    }

    public function cancelCharge(string $chargeId): void
    {
        $this->call(fn () => $this->client()->delete('payments/'.rawurlencode($chargeId)), 'cancelar a cobrança');
    }

    public function verifyWebhook(Request $request): bool
    {
        $token = (string) config('billing.asaas.webhook_token');

        return $token !== '' && hash_equals($token, (string) $request->header('asaas-access-token', ''));
    }

    public function parseWebhook(Request $request): array
    {
        return ['event_id' => $request->input('id') ? (string) $request->input('id') : null, 'event' => $request->input('event'), 'charge_id' => $request->input('payment.id')];
    }

    private function customer(Subscription $subscription): string
    {
        $c = $subscription->company;
        $found = $this->call(fn () => $this->client()->get('customers', ['cpfCnpj' => $c->document]), 'consultar o cliente');
        $id = $found['data'][0]['id'] ?? $this->call(fn () => $this->client()->post('customers', [
            'name' => $c->legal_name, 'cpfCnpj' => $c->document, 'email' => $c->email, 'externalReference' => $c->id, 'notificationDisabled' => false,
        ]), 'cadastrar o cliente')['id'];
        $subscription->forceFill(['gateway_customer_id' => $id])->save();

        return $id;
    }

    private function client(): PendingRequest
    {
        $key = (string) config('billing.asaas.api_key');
        if ($key === '') {
            throw new BusinessRuleViolation('Cobrança da plataforma não configurada (PLATFORM_ASAAS_API_KEY).', 'billing_not_configured');
        }

        return Http::baseUrl(config('billing.asaas.sandbox') ? 'https://api-sandbox.asaas.com/v3/' : 'https://api.asaas.com/v3/')
            ->withHeaders(['access_token' => $key, 'User-Agent' => 'AivexaClinica'])->acceptJson()->asJson()->timeout(20)->connectTimeout(8);
    }

    private function call(\Closure $fn, string $action): array
    {
        try {
            $r = $fn();
        } catch (ConnectionException) {
            throw new BusinessRuleViolation("Sem conexão com o ASAAS para {$action}.", 'billing_gateway');
        }
        if ($r->failed()) {
            $msg = collect($r->json('errors') ?? [])->pluck('description')->filter()->implode(' ');
            throw new BusinessRuleViolation("ASAAS recusou {$action}".($msg ? ": {$msg}" : " (HTTP {$r->status()})."), 'billing_gateway');
        }

        return $r->json() ?? [];
    }
}
