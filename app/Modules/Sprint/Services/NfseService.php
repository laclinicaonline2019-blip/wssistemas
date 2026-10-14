<?php

namespace App\Modules\Sprint\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentCustomer;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Sprint\Models\SprintInvoice;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Models\SprintSetting;
use App\Modules\Sprint\Nfse\AsaasNfseProvider;
use App\Modules\Sprint\Nfse\MockNfseProvider;
use App\Modules\Sprint\Nfse\NfseProvider;
use App\Modules\Sprint\Nfse\NfseResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NFS-e opcional do Melhor Sprint: emitida sobre o valor que FICOU PARA A CLÍNICA
 * (total da venda menos as partes dos profissionais/parceiros, que emitem a própria nota).
 * Tarifa do gateway e comissão são despesas da clínica e não reduzem a base da nota.
 */
class NfseService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    public function provider(PaymentGateway $gateway): NfseProvider
    {
        return app($gateway->provider === 'asaas' ? AsaasNfseProvider::class : MockNfseProvider::class);
    }

    public function issue(?User $actor, SprintSale $sale): SprintInvoice
    {
        $settings = SprintSetting::current();
        if (! $settings->nfse_enabled) {
            throw new BusinessRuleViolation('Emissão de NFS-e desativada. Ative em Melhor Sprint → Configurações.', 'nfse_disabled');
        }
        $gateway = $settings->nfse_gateway_id ? PaymentGateway::query()->where('is_active', true)->find($settings->nfse_gateway_id) : null;
        if (! $gateway || ! in_array($gateway->provider, ['asaas', 'mock'], true)) {
            throw new BusinessRuleViolation('Escolha o emissor da NFS-e (conta ASAAS) em Melhor Sprint → Configurações.', 'nfse_gateway_missing');
        }
        if ($sale->status !== 'paid') {
            throw new BusinessRuleViolation('A NFS-e é emitida depois que a venda é quitada.', 'sale_not_paid', 409);
        }
        if ($sale->house_cents <= 0) {
            throw new BusinessRuleViolation('Esta venda não tem valor da clínica — não há nota a emitir.', 'nothing_to_invoice');
        }

        $lock = Cache::lock("sprint-nfse:{$sale->id}", 60);
        if (! $lock->get()) {
            throw new BusinessRuleViolation('Emissão em andamento. Aguarde alguns segundos.', 'nfse_in_progress', 409);
        }

        try {
            if (SprintInvoice::query()->where('sale_id', $sale->id)->whereNotIn('status', ['error', 'cancelled'])->exists()) {
                throw new BusinessRuleViolation('Esta venda já tem NFS-e emitida ou em processamento.', 'nfse_exists', 409);
            }

            $invoice = SprintInvoice::create([
                'sale_id' => $sale->id, 'provider' => $gateway->provider, 'mode' => $gateway->mode, 'value_cents' => $sale->house_cents,
                'sale_total_cents' => $sale->total_cents, 'description' => $this->description($sale, $settings), 'created_by' => $actor?->id,
            ]);

            try {
                $customerId = $gateway->provider === 'asaas' ? $this->customerId($gateway, $sale) : null;
                $result = $this->provider($gateway)->issue($gateway, $invoice, $settings, $customerId);
            } catch (Throwable $e) {
                $invoice->forceFill(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
                $this->audit->record('sprint.nfse_failed', $sale, metadata: ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

                throw $e;
            }

            $this->apply($invoice, $result);
            $this->audit->record('sprint.nfse_issued', $sale, metadata: ['invoice_id' => $invoice->id, 'value_cents' => $invoice->value_cents, 'status' => $invoice->status]);

            return $invoice;
        } finally {
            $lock->release();
        }
    }

    public function sync(SprintInvoice $invoice): SprintInvoice
    {
        if (! $invoice->provider_invoice_id || in_array($invoice->status, ['cancelled', 'error'], true)) {
            return $invoice;
        }

        $gateway = PaymentGateway::query()->findOrFail(SprintSetting::current()->nfse_gateway_id ?? '');
        $this->apply($invoice, $this->provider($gateway)->fetch($gateway, $invoice->provider_invoice_id));

        return $invoice;
    }

    public function cancel(User $actor, SprintInvoice $invoice): SprintInvoice
    {
        if (! in_array($invoice->status, ['scheduled', 'authorized'], true)) {
            throw new BusinessRuleViolation('Somente notas agendadas ou autorizadas podem ser canceladas.', 'not_cancellable', 409);
        }

        $gateway = PaymentGateway::query()->findOrFail(SprintSetting::current()->nfse_gateway_id ?? '');
        $this->apply($invoice, $this->provider($gateway)->cancel($gateway, $invoice->provider_invoice_id));
        $this->audit->record('sprint.nfse_cancelled', $invoice->sale()->first(), metadata: ['invoice_id' => $invoice->id, 'by' => $actor->id]);

        return $invoice;
    }

    /** Rotina (cron): acompanha notas em processamento e emite as automáticas que falharam por indisponibilidade. */
    public function runPending(int $limit = 50): int
    {
        $rows = $this->context->runAsSystem(fn () => SprintInvoice::query()->withoutGlobalScopes()
            ->whereIn('status', ['scheduled', 'cancelling'])->whereNotNull('provider_invoice_id')
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subMinutes(10)))
            ->orderBy('last_checked_at')->limit($limit)->get(['id', 'company_id']));

        foreach ($rows as $row) {
            try {
                $this->context->runFor($row->company_id, fn () => $this->sync(SprintInvoice::query()->findOrFail($row->id)));
            } catch (Throwable $e) {
                Log::warning('Consulta de NFS-e falhou', ['invoice' => $row->id, 'error' => $e->getMessage()]);
            }
        }

        return $rows->count();
    }

    public function description(SprintSale $sale, SprintSetting $settings): string
    {
        $sale->loadMissing('items');
        $base = trim((string) $settings->nfse_description) ?: 'Serviços prestados pela clínica';
        $services = $sale->items->pluck('description')->unique()->implode(', ');

        return mb_substr($base.': '.$services.'. Venda '.$sale->code().' — valor total '.Format::money($sale->total_cents)
            .($sale->professional_cents > 0 ? ', dos quais '.Format::money($sale->professional_cents).' pertencem a profissionais/parceiros, que emitem documento fiscal próprio' : '')
            .'. Esta nota refere-se somente à parte da clínica: '.Format::money($sale->house_cents).'.', 0, 1000);
    }

    private function apply(SprintInvoice $invoice, NfseResult $r): void
    {
        $invoice->forceFill(array_filter([
            'provider_invoice_id' => $r->id ?? $invoice->provider_invoice_id, 'status' => $r->status, 'number' => $r->number,
            'verification_code' => $r->verificationCode, 'pdf_url' => $r->pdfUrl, 'xml_url' => $r->xmlUrl, 'error' => $r->error,
        ], fn ($v) => $v !== null) + ['last_checked_at' => now()]);

        if ($r->status === 'authorized' && ! $invoice->issued_at) {
            $invoice->issued_at = now();
        }
        $invoice->save();
    }

    private function customerId(PaymentGateway $gateway, SprintSale $sale): ?string
    {
        $patient = $sale->patient_id ? Patient::query()->find($sale->patient_id) : null;
        if (! $patient) {
            return null;
        }

        $existing = PaymentCustomer::query()->where('gateway_id', $gateway->id)->where('patient_id', $patient->id)->value('provider_customer_id');
        if ($existing) {
            return $existing;
        }

        $id = $this->payments->provider($gateway)->ensureCustomer($gateway, $patient);
        if ($id) {
            PaymentCustomer::query()->firstOrCreate(['gateway_id' => $gateway->id, 'patient_id' => $patient->id], ['provider_customer_id' => $id]);
        }

        return $id;
    }
}
