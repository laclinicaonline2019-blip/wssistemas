<?php

namespace App\Modules\Sprint\Nfse;

use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Providers\GatewayException;
use App\Modules\Sprint\Models\SprintInvoice;
use App\Modules\Sprint\Models\SprintSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * NFS-e pelo ASAAS (API v3 /invoices). A clínica habilita a emissão de notas no painel do ASAAS
 * (certificado digital, inscrição municipal e serviços da prefeitura) — o sistema só envia o valor
 * da PARTE DA CLÍNICA, a descrição e o código do serviço, e acompanha a autorização.
 *
 * - Agendar: POST /invoices · emitir já: POST /invoices/{id}/authorize
 * - Consultar: GET /invoices/{id} (número, código de verificação, PDF e XML)
 * - Cancelar: POST /invoices/{id}/cancel
 */
class AsaasNfseProvider implements NfseProvider
{
    private const STATUS = [
        'SCHEDULED' => 'scheduled', 'SYNCHRONIZED' => 'scheduled', 'AUTHORIZATION_PENDING' => 'scheduled',
        'AUTHORIZED' => 'authorized', 'PROCESSING_CANCELLATION' => 'cancelling', 'CANCELED' => 'cancelled',
        'CANCELLATION_DENIED' => 'authorized', 'ERROR' => 'error',
    ];

    public function issue(PaymentGateway $gateway, SprintInvoice $invoice, SprintSetting $settings, ?string $customerId): NfseResult
    {
        if (! $customerId) {
            throw new GatewayException('Para emitir a NFS-e pelo ASAAS a venda precisa de um paciente com CPF.', 'customer_required');
        }

        $iss = round($settings->nfse_iss_bp / 100, 2);
        $created = $this->json(fn () => $this->client($gateway)->post('invoices', array_filter([
            'customer' => $customerId,
            'serviceDescription' => mb_substr($invoice->description, 0, 1000),
            'observations' => 'Valor referente somente à parte da clínica. Venda '.$invoice->sale_id,
            'externalReference' => $invoice->id,
            'value' => round($invoice->value_cents / 100, 2),
            'deductions' => 0,
            'effectiveDate' => now('America/Sao_Paulo')->toDateString(),
            'municipalServiceCode' => $settings->nfse_service_code,
            'municipalServiceName' => $settings->nfse_service_name,
            'taxes' => ['retainIss' => false, 'iss' => $iss, 'cofins' => 0, 'csll' => 0, 'inss' => 0, 'ir' => 0, 'pis' => 0],
        ], fn ($v) => $v !== null && $v !== '')), 'agendar a NFS-e');

        $id = $created['id'] ?? throw new GatewayException('Resposta inesperada do ASAAS ao agendar a NFS-e.');

        try {
            $data = $this->json(fn () => $this->client($gateway)->post("invoices/{$id}/authorize"), 'emitir a NFS-e');
        } catch (GatewayException) {
            $data = $created; // fica agendada; a rotina consulta a autorização depois
        }

        return $this->result($data + ['id' => $id]);
    }

    public function fetch(PaymentGateway $gateway, string $providerInvoiceId): NfseResult
    {
        return $this->result($this->json(fn () => $this->client($gateway)->get("invoices/{$providerInvoiceId}"), 'consultar a NFS-e'));
    }

    public function cancel(PaymentGateway $gateway, string $providerInvoiceId): NfseResult
    {
        return $this->result($this->json(fn () => $this->client($gateway)->post("invoices/{$providerInvoiceId}/cancel"), 'cancelar a NFS-e'));
    }

    private function result(array $d): NfseResult
    {
        $status = self::STATUS[$d['status'] ?? ''] ?? 'scheduled';

        return new NfseResult(
            $d['id'] ?? null, $status, $d['number'] ?? null, $d['validationCode'] ?? null, $d['pdfUrl'] ?? null, $d['xmlUrl'] ?? null,
            $status === 'error' ? mb_substr((string) ($d['statusDescription'] ?? 'Prefeitura recusou a nota.'), 0, 500) : null,
        );
    }

    private function client(PaymentGateway $gateway): PendingRequest
    {
        $key = $gateway->credential('api_key') ?? throw new GatewayException('Chave de API do ASAAS não configurada.', 'not_configured');

        return Http::baseUrl($gateway->mode === 'production' ? 'https://api.asaas.com/v3/' : 'https://api-sandbox.asaas.com/v3/')
            ->withHeaders(['access_token' => $key, 'User-Agent' => 'MelhorSprint'])
            ->acceptJson()->asJson()->timeout(30)->connectTimeout(8);
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
