<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cielo — Link de Pagamento (Super Link). O paciente paga (cartão, PIX ou boleto,
 * conforme habilitado na loja) na página da Cielo: nenhum dado de cartão passa pelo
 * sistema. Não há split nativo — a parte do médico vira repasse interno.
 *
 * - OAuth2: POST /api/public/v2/token (client_credentials, Basic ClientId:ClientSecret)
 * - Link:   POST /api/public/v1/products · consulta GET /api/public/v1/products/{id}/payments
 * - Pedido: GET /api/public/v2/orders/{checkout_cielo_order_number} · cancelamento PUT …/void
 * - Notificação: POST para a URL configurada no painel Cielo (sem assinatura) — por isso a URL
 *   carrega um token secreto e o status SEMPRE é confirmado pela consulta à API.
 * A Cielo não oferece sandbox para o Link de Pagamento: homologue em produção com valor baixo.
 */
class CieloProvider implements PaymentProvider
{
    private const BASE = 'https://cieloecommerce.cielo.com.br/api/public/';

    private const STATUS = [
        'Pending' => 'pending', 'Paid' => 'paid', 'Authorized' => 'pending', 'Denied' => 'failed', 'Expired' => 'failed',
        'Voided' => 'refunded', 'NotFinalized' => 'pending', 'Chargeback' => 'review',
        // Códigos numéricos das notificações
        '1' => 'pending', '2' => 'paid', '3' => 'failed', '4' => 'failed', '5' => 'refunded', '6' => 'pending', '7' => 'pending', '8' => 'review',
    ];

    public function supportsNativeSplit(): bool
    {
        return false;
    }

    public function testConnection(PaymentGateway $gateway): string
    {
        $this->token($gateway, fresh: true);

        return 'Conexão OK com a Cielo (token OAuth obtido).';
    }

    public function ensureCustomer(PaymentGateway $gateway, Patient $patient): ?string
    {
        return null;
    }

    public function createCharge(PaymentGateway $gateway, ChargeRequest $request): ChargeResult
    {
        $link = $this->json(fn () => Http::withToken($this->token($gateway))->acceptJson()->asJson()->timeout(20)
            ->post(self::BASE.'v1/products/', [
                'type' => 'Digital',
                'name' => mb_substr($request->description, 0, 128),
                'description' => mb_substr($request->description, 0, 256),
                'price' => (string) $request->amountCents,
                'expirationDate' => CarbonImmutable::parse($request->dueDate)->format('Y-m-d').' 23:59:00',
                'maxNumberOfInstallments' => (string) max(1, min(12, $request->maxInstallments)),
                'quantity' => 1,
                'sku' => substr(preg_replace('/[^A-Za-z0-9]/', '', $request->reference), -32),
                'OrderNumber' => substr(preg_replace('/[^A-Za-z0-9]/', '', $request->reference), -20),
                'shipping' => ['type' => 'WithoutShipping'],
            ]), 'criar o link de pagamento');

        return new ChargeResult($link['id'] ?? throw new GatewayException('Resposta inesperada da Cielo.'), $link['shortUrl'] ?? null);
    }

    public function fetchCharge(PaymentGateway $gateway, string $providerChargeId): RemoteCharge
    {
        $data = $this->json(fn () => Http::withToken($this->token($gateway))->acceptJson()->timeout(20)
            ->get(self::BASE."v1/products/{$providerChargeId}/payments"), 'consultar o link');

        $orders = collect($data['orders'] ?? []);
        $paid = $orders->first(fn ($o) => ($o['payment']['status'] ?? null) === 'Paid');

        if ($paid) {
            return new RemoteCharge('paid', (int) ($paid['payment']['price'] ?? 0), null,
                isset($paid['payment']['createdDate']) ? CarbonImmutable::parse($paid['payment']['createdDate'], 'America/Sao_Paulo') : CarbonImmutable::now(),
                'credit_card', $paid['orderNumber'] ?? null);
        }

        $statuses = $orders->pluck('payment.status')->filter();
        foreach (['Chargeback' => 'review', 'Voided' => 'refunded'] as $s => $mapped) {
            if ($statuses->contains($s)) {
                return new RemoteCharge($mapped, detail: $s);
            }
        }

        // Pedido negado não encerra o link: o paciente pode tentar de novo.
        return new RemoteCharge('pending', detail: $statuses->last());
    }

    public function cancelCharge(PaymentGateway $gateway, string $providerChargeId): void
    {
        $this->json(fn () => Http::withToken($this->token($gateway))->acceptJson()->timeout(20)
            ->delete(self::BASE."v1/products/{$providerChargeId}"), 'excluir o link');
    }

    public function refund(PaymentGateway $gateway, string $providerChargeId): void
    {
        $data = $this->json(fn () => Http::withToken($this->token($gateway))->acceptJson()->timeout(20)
            ->get(self::BASE."v1/products/{$providerChargeId}/payments"), 'consultar o link');
        $order = collect($data['orders'] ?? [])->first(fn ($o) => ($o['payment']['status'] ?? null) === 'Paid')
            ?? throw new GatewayException('Nenhum pagamento aprovado neste link para estornar.', 'nothing_to_refund');

        $this->json(fn () => Http::withToken($this->token($gateway))->acceptJson()->timeout(20)
            ->put(self::BASE."v2/orders/{$order['orderNumber']}/void"), 'estornar o pagamento');
    }

    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool
    {
        $sent = (string) $request->query('token', '');

        return $sent !== '' && hash_equals((string) $gateway->webhook_token, $sent);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->isJson() ? $request->json()->all() : $request->post();
        $order = (string) ($data['checkout_cielo_order_number'] ?? '');
        $status = (string) ($data['payment_status'] ?? '');

        return new WebhookEvent(
            $order !== '' ? "{$order}:{$status}" : hash('sha256', json_encode($data)),
            'payment_status_'.$status,
            $data['product_id'] ?? null,
            ['checkout_cielo_order_number' => $order, 'payment_status' => $status, 'product_id' => $data['product_id'] ?? null, 'amount' => $data['amount'] ?? null],
        );
    }

    /** Token OAuth (válido ~20 min) em cache por gateway. */
    private function token(PaymentGateway $gateway, bool $fresh = false): string
    {
        $key = "cielo-token:{$gateway->id}";

        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }

        $id = $gateway->credential('client_id');
        $secret = $gateway->credential('client_secret');
        if (! $id || ! $secret) {
            throw new GatewayException('ClientId/ClientSecret da Cielo não configurados.', 'not_configured');
        }

        $data = $this->json(fn () => Http::withBasicAuth($id, $secret)->asForm()->acceptJson()->timeout(15)
            ->post(self::BASE.'v2/token', ['grant_type' => 'client_credentials']), 'autenticar');
        $token = $data['access_token'] ?? throw new GatewayException('Cielo não retornou o token de acesso.');
        Cache::put($key, $token, now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 1200) - 60)));

        return $token;
    }

    private function json(\Closure $call, string $action): array
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new GatewayException("Não foi possível conectar à Cielo para {$action}. Tente novamente.", 'gateway_unavailable');
        }

        if ($response->failed()) {
            throw new GatewayException("Cielo recusou {$action} (HTTP {$response->status()}).", 'gateway_rejected');
        }

        return $response->json() ?? [];
    }

    /** Status numérico/textual → status interno (usado em testes e diagnósticos). */
    public static function mapStatus(string $status): string
    {
        return self::STATUS[$status] ?? 'pending';
    }
}
