<?php

namespace App\Modules\Payments\Providers;

use App\Core\Support\Format;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * ASAAS — API v3 (PIX, boleto, cartão via fatura do ASAAS, split nativo por walletId).
 * Sandbox: https://api-sandbox.asaas.com/v3 · Produção: https://api.asaas.com/v3
 * Webhook: header "asaas-access-token" com o token definido na configuração do webhook.
 * Dados de cartão nunca passam pelo sistema (o paciente paga na fatura do ASAAS).
 */
class AsaasProvider implements PaymentProvider
{
    private const STATUS = [
        'PENDING' => 'pending', 'AWAITING_RISK_ANALYSIS' => 'pending', 'CONFIRMED' => 'paid', 'RECEIVED' => 'paid',
        'OVERDUE' => 'overdue', 'REFUNDED' => 'refunded', 'REFUND_REQUESTED' => 'paid', 'REFUND_IN_PROGRESS' => 'paid',
        // "Recebido em dinheiro" marcado no painel do ASAAS e disputas: exigem conferência humana.
        'RECEIVED_IN_CASH' => 'review', 'CHARGEBACK_REQUESTED' => 'review', 'CHARGEBACK_DISPUTE' => 'review',
        'AWAITING_CHARGEBACK_REVERSAL' => 'review', 'DUNNING_REQUESTED' => 'overdue', 'DUNNING_RECEIVED' => 'paid',
    ];

    private const BILLING = ['pix' => 'PIX', 'boleto' => 'BOLETO', 'credit_card' => 'CREDIT_CARD', 'undefined' => 'UNDEFINED'];

    private const METHODS = ['PIX' => 'pix', 'BOLETO' => 'boleto', 'CREDIT_CARD' => 'credit_card', 'DEBIT_CARD' => 'debit_card'];

    public function supportsNativeSplit(): bool
    {
        return true;
    }

    public function testConnection(PaymentGateway $gateway): string
    {
        $balance = $this->json(fn () => $this->client($gateway)->get('finance/balance'), 'consultar o saldo');

        return 'Conexão OK. Saldo disponível: '.Format::money((int) round(($balance['balance'] ?? 0) * 100));
    }

    public function ensureCustomer(PaymentGateway $gateway, Patient $patient): ?string
    {
        $cpf = Format::digits($patient->cpf);
        if (! $cpf) {
            throw new GatewayException('O ASAAS exige o CPF do paciente (ou do responsável) para emitir a cobrança. Complete o cadastro.', 'cpf_required');
        }

        $data = $this->json(fn () => $this->client($gateway)->post('customers', array_filter([
            'name' => $patient->name, 'cpfCnpj' => $cpf, 'email' => $patient->email,
            'mobilePhone' => Format::digits($patient->whatsapp ?? $patient->phone), 'externalReference' => $patient->id,
            'notificationDisabled' => true,
        ], fn ($v) => $v !== null && $v !== '')), 'cadastrar o cliente');

        return $data['id'] ?? throw new GatewayException('Resposta inesperada do ASAAS ao cadastrar o cliente.');
    }

    public function createCharge(PaymentGateway $gateway, ChargeRequest $request): ChargeResult
    {
        $body = [
            'customer' => $request->customerId, 'billingType' => self::BILLING[$request->billingType] ?? 'UNDEFINED',
            'value' => round($request->amountCents / 100, 2), 'dueDate' => $request->dueDate,
            'description' => mb_substr($request->description, 0, 500), 'externalReference' => $request->reference,
        ];

        if ($request->split) {
            $body['split'] = [array_filter([
                'walletId' => $request->split['wallet_id'],
                'percentualValue' => $request->split['type'] === 'percent' ? round($request->split['value'] / 100, 2) : null,
                'fixedValue' => $request->split['type'] === 'fixed' ? round($request->split['value'] / 100, 2) : null,
            ], fn ($v) => $v !== null)];
        }

        $client = $this->client($gateway);
        $payment = $this->json(fn () => $client->post('payments', $body), 'criar a cobrança');
        $id = $payment['id'] ?? throw new GatewayException('Resposta inesperada do ASAAS ao criar a cobrança.');

        $pix = null;
        if (in_array($request->billingType, ['pix', 'undefined'], true)) {
            try {
                $pix = $this->json(fn () => $client->get("payments/{$id}/pixQrCode"), 'gerar o QR Code PIX');
            } catch (GatewayException) {
                $pix = null; // PIX indisponível na conta: o link da fatura continua válido
            }
        }

        return new ChargeResult($id, $payment['invoiceUrl'] ?? null, $pix['payload'] ?? null, $pix['encodedImage'] ?? null);
    }

    public function fetchCharge(PaymentGateway $gateway, string $providerChargeId): RemoteCharge
    {
        $p = $this->json(fn () => $this->client($gateway)->get("payments/{$providerChargeId}"), 'consultar a cobrança');

        if (! empty($p['deleted'])) {
            return new RemoteCharge('cancelled', detail: 'Cobrança removida no ASAAS');
        }

        $status = self::STATUS[$p['status'] ?? ''] ?? 'pending';
        $paidAt = $p['clientPaymentDate'] ?? $p['paymentDate'] ?? $p['confirmedDate'] ?? null;

        return new RemoteCharge(
            $status,
            isset($p['value']) ? (int) round($p['value'] * 100) : null,
            isset($p['netValue']) ? (int) round($p['netValue'] * 100) : null,
            $paidAt ? CarbonImmutable::parse($paidAt, 'America/Sao_Paulo')->setTimeFrom(CarbonImmutable::now('America/Sao_Paulo')) : null,
            self::METHODS[$p['billingType'] ?? ''] ?? null,
            $p['status'] ?? null,
        );
    }

    public function cancelCharge(PaymentGateway $gateway, string $providerChargeId): void
    {
        $this->json(fn () => $this->client($gateway)->delete("payments/{$providerChargeId}"), 'cancelar a cobrança');
    }

    public function refund(PaymentGateway $gateway, string $providerChargeId): void
    {
        $this->json(fn () => $this->client($gateway)->post("payments/{$providerChargeId}/refund"), 'estornar o pagamento');
    }

    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool
    {
        $sent = (string) $request->header('asaas-access-token', '');

        return $sent !== '' && hash_equals((string) $gateway->webhook_token, $sent);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->json()->all();
        $payment = $data['payment'] ?? [];
        $eventId = $data['id'] ?? hash('sha256', ($data['event'] ?? '').'|'.($payment['id'] ?? '').'|'.($payment['status'] ?? '').'|'.($data['dateCreated'] ?? ''));

        // Guarda só o necessário para diagnóstico (sem dados do pagador).
        return new WebhookEvent((string) $eventId, $data['event'] ?? null, $payment['id'] ?? null, [
            'event' => $data['event'] ?? null, 'payment_id' => $payment['id'] ?? null, 'status' => $payment['status'] ?? null,
            'value' => $payment['value'] ?? null, 'billingType' => $payment['billingType'] ?? null, 'externalReference' => $payment['externalReference'] ?? null,
        ]);
    }

    private function client(PaymentGateway $gateway): PendingRequest
    {
        $key = $gateway->credential('api_key') ?? throw new GatewayException('Chave de API do ASAAS não configurada.', 'not_configured');

        return Http::baseUrl($gateway->mode === 'production' ? 'https://api.asaas.com/v3/' : 'https://api-sandbox.asaas.com/v3/')
            ->withHeaders(['access_token' => $key, 'User-Agent' => 'AivexaClinica'])
            ->acceptJson()->asJson()->timeout(20)->connectTimeout(8);
    }

    private function json(\Closure $call, string $action): array
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new GatewayException("Não foi possível conectar ao ASAAS para {$action}. Tente novamente.", 'gateway_unavailable');
        }

        if ($response->failed()) {
            $msg = collect($response->json('errors') ?? [])->pluck('description')->filter()->implode(' ');
            throw new GatewayException("ASAAS recusou {$action}".($msg ? ": {$msg}" : " (HTTP {$response->status()})."), 'gateway_rejected');
        }

        return $response->json() ?? [];
    }
}
