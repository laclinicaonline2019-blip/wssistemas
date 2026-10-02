<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Contrato de um gateway de pagamento. A confirmação NUNCA vem do webhook em si:
 * o webhook só avisa; o status/valor confiável vem de fetchCharge() (consulta à API).
 */
interface PaymentProvider
{
    public function supportsNativeSplit(): bool;

    /** Testa as credenciais. Lança exceção com mensagem amigável em caso de falha. */
    public function testConnection(PaymentGateway $gateway): string;

    /** Cliente do paciente no gateway (quando exigido). */
    public function ensureCustomer(PaymentGateway $gateway, Patient $patient): ?string;

    public function createCharge(PaymentGateway $gateway, ChargeRequest $request): ChargeResult;

    public function fetchCharge(PaymentGateway $gateway, string $providerChargeId): RemoteCharge;

    public function cancelCharge(PaymentGateway $gateway, string $providerChargeId): void;

    public function refund(PaymentGateway $gateway, string $providerChargeId): void;

    /** Autenticidade do webhook (token/assinatura) — comparação em tempo constante. */
    public function verifyWebhook(PaymentGateway $gateway, Request $request): bool;

    public function parseWebhook(Request $request): WebhookEvent;
}
