<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Gateway MOCK — para demonstração e testes. Não movimenta dinheiro real e é
 * identificado como "MOCK" em todas as telas, cobranças e lançamentos. O "lado do
 * gateway" é simulado em cache; a confirmação segue o MESMO fluxo dos gateways reais
 * (webhook com token → consulta → baixa).
 */
class MockProvider implements PaymentProvider
{
    /** Tarifa simulada (1,99%) para demonstrar o lançamento de tarifas. */
    public const FEE_BP = 199;

    public function supportsNativeSplit(): bool
    {
        return true;
    }

    public function testConnection(PaymentGateway $gateway): string
    {
        return 'Gateway MOCK: nenhuma conexão externa (simulação).';
    }

    public function ensureCustomer(PaymentGateway $gateway, Patient $patient): ?string
    {
        return 'mock_cus_'.substr($patient->id, -10);
    }

    public function createCharge(PaymentGateway $gateway, ChargeRequest $request): ChargeResult
    {
        $id = 'mock_pay_'.Str::lower((string) Str::ulid());
        $this->put($id, ['status' => 'pending', 'amount_cents' => $request->amountCents]);

        return new ChargeResult($id, null, $request->billingType === 'boleto' ? null : '00020126MOCK-PIX-SEM-VALOR-REAL'.strtoupper(substr($id, -8)));
    }

    public function fetchCharge(PaymentGateway $gateway, string $providerChargeId): RemoteCharge
    {
        $s = Cache::get($this->key($providerChargeId)) ?? ['status' => 'cancelled'];
        $paid = $s['paid_cents'] ?? null;

        return new RemoteCharge(
            $s['status'], $paid, $paid !== null ? $paid - intdiv($paid * self::FEE_BP + 5000, 10000) : null,
            isset($s['paid_at']) ? CarbonImmutable::parse($s['paid_at']) : null, $s['method'] ?? 'pix', 'MOCK',
        );
    }

    public function cancelCharge(PaymentGateway $gateway, string $providerChargeId): void
    {
        $this->update($providerChargeId, ['status' => 'cancelled']);
    }

    public function refund(PaymentGateway $gateway, string $providerChargeId): void
    {
        $this->update($providerChargeId, ['status' => 'refunded']);
    }

    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool
    {
        $sent = (string) $request->header('X-Mock-Token', '');

        return $sent !== '' && hash_equals((string) $gateway->webhook_token, $sent);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = $request->json()->all();

        return new WebhookEvent((string) ($data['id'] ?? Str::ulid()), $data['event'] ?? null, $data['charge_id'] ?? null, $data);
    }

    /** Simula o que o "gateway" faria (pagamento, vencimento, estorno). Só para MOCK. */
    public function simulate(string $providerChargeId, string $outcome, ?int $paidCents = null, string $method = 'pix'): void
    {
        $current = Cache::get($this->key($providerChargeId)) ?? [];
        $this->put($providerChargeId, match ($outcome) {
            'paid' => ['status' => 'paid', 'paid_cents' => $paidCents ?? ($current['amount_cents'] ?? 0), 'paid_at' => now()->toIso8601String(), 'method' => $method] + $current,
            'overdue' => ['status' => 'overdue'] + $current,
            'refunded' => ['status' => 'refunded'] + $current,
            default => $current,
        });
    }

    private function key(string $id): string
    {
        return 'mock-payment:'.$id;
    }

    private function put(string $id, array $state): void
    {
        Cache::put($this->key($id), $state, now()->addDays(60));
    }

    private function update(string $id, array $changes): void
    {
        $this->put($id, $changes + (Cache::get($this->key($id)) ?? []));
    }
}
