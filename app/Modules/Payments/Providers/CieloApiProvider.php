<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cielo — API E-commerce 3.0 com SPLIT DE PAGAMENTO (cartão de crédito).
 *
 * - O paciente digita o cartão na página de pagamento do sistema, mas os dados vão do
 *   navegador DIRETO para a Cielo (Silent Order Post): o servidor recebe só o
 *   PaymentToken temporário — nenhum número de cartão/CVV passa pelo sistema.
 * - Venda: POST /1/sales/ com Payment.Type = "SplittedCreditCard" e SplitPayments
 *   [{SubordinateMerchantId do médico, Amount da parte dele}]; a clínica (marketplace)
 *   fica com o restante. A Cielo liquida cada parte na conta de cada um.
 * - Confirmação: consulta GET /1/sales/{PaymentId} (fonte de verdade) — o "Post de
 *   Notificação" da Cielo só avisa que algo mudou.
 * - Exige contrato de Split com a Cielo e o médico cadastrado como subordinado.
 *
 * Credenciais: MerchantId/MerchantKey (vendas) e ClientId/ClientSecret (OAuth do SOP).
 */
class CieloApiProvider implements CardTokenProvider, PaymentProvider
{
    /** Payment.Status da API 3.0 → status interno. */
    private const STATUS = [
        0 => 'pending', 1 => 'pending', 2 => 'paid', 3 => 'failed', 10 => 'refunded', 11 => 'refunded',
        12 => 'pending', 13 => 'failed', 20 => 'pending',
    ];

    public const BRANDS = ['Visa' => 'Visa', 'Master' => 'Mastercard', 'Elo' => 'Elo', 'Amex' => 'American Express', 'Hipercard' => 'Hipercard', 'Diners' => 'Diners', 'Discover' => 'Discover', 'JCB' => 'JCB', 'Aura' => 'Aura'];

    public function supportsNativeSplit(): bool
    {
        return true;
    }

    public function testConnection(PaymentGateway $gateway): string
    {
        $this->credentials($gateway);
        $this->oauthToken($gateway, fresh: true);

        return 'Conexão OK com a Cielo (OAuth do Silent Order Post). As vendas usam MerchantId/MerchantKey — valide com uma venda de teste no SANDBOX.';
    }

    public function ensureCustomer(PaymentGateway $gateway, Patient $patient): ?string
    {
        return null;
    }

    /** A venda só acontece quando o paciente informa o cartão na página de pagamento. */
    public function createCharge(PaymentGateway $gateway, ChargeRequest $request): ChargeResult
    {
        if ($request->billingType !== 'credit_card') {
            throw new GatewayException('A API E-commerce Cielo com split aceita somente cartão de crédito.', 'invalid_billing_type');
        }
        $this->credentials($gateway);

        return new ChargeResult(null, null);
    }

    public function tokenizationConfig(PaymentGateway $gateway): array
    {
        $sandbox = $gateway->mode !== 'production';
        $base = $sandbox ? 'https://transactionsandbox.pagador.com.br' : 'https://transaction.pagador.com.br';
        [$merchantId] = $this->credentials($gateway);

        $data = $this->json(fn () => Http::withToken($this->oauthToken($gateway))->withHeaders(['MerchantId' => $merchantId])
            ->acceptJson()->timeout(15)->post($base.'/post/api/public/v2/accesstoken'), 'iniciar a tokenização do cartão');

        return [
            'access_token' => $data['AccessToken'] ?? throw new GatewayException('Cielo não retornou o AccessToken do Silent Order Post.'),
            'environment' => $sandbox ? 'sandbox' : 'production',
            'script_url' => $base.'/post/Scripts/silentorderpost-1.0.min.js',
        ];
    }

    public function authorizeCard(PaymentGateway $gateway, PaymentCharge $charge, string $paymentToken, string $brand, int $installments, string $holderName, ?array $split): array
    {
        [$merchantId, $merchantKey] = $this->credentials($gateway);

        $payment = [
            'Type' => 'SplittedCreditCard',
            'Amount' => $charge->amount_cents,
            'Installments' => max(1, $installments),
            'Capture' => true,
            'SoftDescriptor' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $gateway->setting('soft_descriptor', 'CLINICA')), 0, 13) ?: 'CLINICA',
            'CreditCard' => ['PaymentToken' => $paymentToken, 'Brand' => $brand, 'Holder' => mb_substr($holderName, 0, 25)],
        ];

        if ($split) {
            $payment['SplitPayments'] = [['SubordinateMerchantId' => $split['subordinate_id'], 'Amount' => $split['amount_cents']]];
        }

        $response = $this->json(fn () => Http::withHeaders(['MerchantId' => $merchantId, 'MerchantKey' => $merchantKey, 'RequestId' => $charge->id])
            ->acceptJson()->asJson()->timeout(30)
            ->post($this->apiBase($gateway).'1/sales/', [
                'MerchantOrderId' => substr(preg_replace('/[^A-Za-z0-9]/', '', $charge->id), -20),
                'Customer' => ['Name' => mb_substr($holderName, 0, 255)],
                'Payment' => $payment,
            ]), 'processar o cartão', allowClientError: true);

        $p = $response['Payment'] ?? [];
        $status = (int) ($p['Status'] ?? -1);

        if (! in_array($status, [1, 2], true)) {
            $message = $p['ReturnMessage'] ?? collect($response)->pluck('Message')->filter()->implode(' ') ?: 'Pagamento não autorizado.';

            return ['approved' => false, 'payment_id' => $p['PaymentId'] ?? null, 'message' => 'Cartão não aprovado: '.$message];
        }

        return ['approved' => true, 'payment_id' => $p['PaymentId'], 'message' => $p['ReturnMessage'] ?? 'Aprovado'];
    }

    public function fetchCharge(PaymentGateway $gateway, string $providerChargeId): RemoteCharge
    {
        [$merchantId, $merchantKey] = $this->credentials($gateway);
        $data = $this->json(fn () => Http::withHeaders(['MerchantId' => $merchantId, 'MerchantKey' => $merchantKey])->acceptJson()->timeout(20)
            ->get($this->queryBase($gateway)."1/sales/{$providerChargeId}"), 'consultar a venda');

        $p = $data['Payment'] ?? [];
        $status = self::STATUS[(int) ($p['Status'] ?? -1)] ?? 'review';
        $captured = $p['CapturedAmount'] ?? $p['Amount'] ?? null;
        $date = $p['CapturedDate'] ?? $p['ReceivedDate'] ?? null;

        return new RemoteCharge(
            $status, $captured !== null ? (int) $captured : null, null,
            $date ? CarbonImmutable::parse($date, 'America/Sao_Paulo') : null, 'credit_card', 'Status '.($p['Status'] ?? '?'),
        );
    }

    public function cancelCharge(PaymentGateway $gateway, string $providerChargeId): void
    {
        $this->refund($gateway, $providerChargeId);
    }

    /** Cancelamento/estorno total: a Cielo desfaz também o split. */
    public function refund(PaymentGateway $gateway, string $providerChargeId): void
    {
        [$merchantId, $merchantKey] = $this->credentials($gateway);
        $this->json(fn () => Http::withHeaders(['MerchantId' => $merchantId, 'MerchantKey' => $merchantKey])->acceptJson()->timeout(30)
            ->put($this->apiBase($gateway)."1/sales/{$providerChargeId}/void"), 'estornar a venda');
    }

    /** "Post de Notificação" da Cielo não é assinado: token secreto na URL + consulta obrigatória. */
    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool
    {
        $sent = (string) $request->query('token', '');

        return $sent !== '' && hash_equals((string) $gateway->webhook_token, $sent);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->isJson() ? $request->json()->all() : $request->post();
        $paymentId = (string) ($data['PaymentId'] ?? '');
        $change = (string) ($data['ChangeType'] ?? '');

        // A notificação só diz "mudou"; o estado real vem da consulta. O ID inclui o instante para
        // não descartar mudanças posteriores do mesmo pagamento (o processamento é idempotente pelo estado).
        return new WebhookEvent("{$paymentId}:{$change}:".now()->format('YmdHis'), 'change_type_'.$change, $paymentId ?: null,
            ['PaymentId' => $paymentId, 'ChangeType' => $change]);
    }

    private function apiBase(PaymentGateway $gateway): string
    {
        return $gateway->mode === 'production' ? 'https://api.cieloecommerce.cielo.com.br/' : 'https://apisandbox.cieloecommerce.cielo.com.br/';
    }

    private function queryBase(PaymentGateway $gateway): string
    {
        return $gateway->mode === 'production' ? 'https://apiquery.cieloecommerce.cielo.com.br/' : 'https://apiquerysandbox.cieloecommerce.cielo.com.br/';
    }

    /** @return array{0: string, 1: string} */
    private function credentials(PaymentGateway $gateway): array
    {
        $id = $gateway->credential('merchant_id');
        $key = $gateway->credential('merchant_key');

        if (! $id || ! $key) {
            throw new GatewayException('MerchantId/MerchantKey da Cielo não configurados.', 'not_configured');
        }

        return [$id, $key];
    }

    /** OAuth2 (client_credentials) do Silent Order Post — token em cache por gateway. */
    private function oauthToken(PaymentGateway $gateway, bool $fresh = false): string
    {
        $key = "cielo-sop-oauth:{$gateway->id}";
        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }

        $id = $gateway->credential('client_id');
        $secret = $gateway->credential('client_secret');
        if (! $id || ! $secret) {
            throw new GatewayException('ClientId/ClientSecret da Cielo (Silent Order Post) não configurados.', 'not_configured');
        }

        $url = $gateway->mode === 'production' ? 'https://auth.braspag.com.br/oauth2/token' : 'https://authsandbox.braspag.com.br/oauth2/token';
        $data = $this->json(fn () => Http::withBasicAuth($id, $secret)->asForm()->acceptJson()->timeout(15)
            ->post($url, ['grant_type' => 'client_credentials']), 'autenticar na Cielo');
        $token = $data['access_token'] ?? throw new GatewayException('Cielo não retornou o token OAuth.');
        Cache::put($key, $token, now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 1200) - 60)));

        return $token;
    }

    private function json(\Closure $call, string $action, bool $allowClientError = false): array
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException) {
            throw new GatewayException("Não foi possível conectar à Cielo para {$action}. Tente novamente.", 'gateway_unavailable');
        }

        if ($response->serverError() || ($response->clientError() && ! $allowClientError)) {
            $msg = collect($response->json() ?? [])->pluck('Message')->filter()->implode(' ');
            throw new GatewayException("Cielo recusou {$action}".($msg ? ": {$msg}" : " (HTTP {$response->status()})."), 'gateway_rejected');
        }

        return $response->json() ?? [];
    }
}
