<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentCustomer;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Providers\AsaasProvider;
use App\Modules\Payments\Providers\ChargeRequest;
use App\Modules\Payments\Providers\CieloProvider;
use App\Modules\Payments\Providers\MockProvider;
use App\Modules\Payments\Providers\PaymentProvider;
use App\Modules\Payments\Providers\RemoteCharge;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cobranças online.
 *
 * Regra de ouro: uma cobrança só vira recebimento quando (1) chega um webhook
 * AUTENTICADO (ou a rotina de sincronização roda) e (2) a CONSULTA à API do gateway
 * confirma o pagamento e o valor. Webhook repetido não duplica nada (evento único);
 * clique duplo não duplica cobrança (chave de idempotência).
 */
class PaymentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FinanceService $finance,
        private readonly SplitService $splits,
        private readonly AuditLogger $audit,
    ) {}

    public function provider(PaymentGateway $gateway): PaymentProvider
    {
        return app(match ($gateway->provider) {
            'asaas' => AsaasProvider::class,
            'cielo' => CieloProvider::class,
            default => MockProvider::class,
        });
    }

    public function defaultGateway(): ?PaymentGateway
    {
        return PaymentGateway::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('created_at')->first();
    }

    // ------------------------------------------------------------------ configuração

    public function saveGateway(User $actor, array $data, ?PaymentGateway $gateway = null): PaymentGateway
    {
        $provider = $gateway?->provider ?? $data['provider'];
        $mode = $provider === 'mock' ? 'mock' : ($data['mode'] ?? 'sandbox');

        if ($provider !== 'mock' && $mode === 'mock') {
            throw new BusinessRuleViolation('Modo MOCK só para o gateway de simulação.', 'invalid_mode');
        }

        // Campos de credencial em branco mantêm o valor atual (nunca são exibidos de volta).
        $credentials = array_filter($data['credentials'] ?? [], fn ($v) => is_string($v) && trim($v) !== '');
        $credentials = array_map('trim', $credentials) + ($gateway?->credentials ?? []);

        return DB::transaction(function () use ($data, $gateway, $provider, $mode, $credentials) {
            $gateway ??= new PaymentGateway(['provider' => $provider, 'webhook_token' => Str::random(40)]);
            $gateway->fill([
                'mode' => $mode, 'name' => $data['name'] ?? PaymentGateway::PROVIDERS[$provider],
                'credentials' => $credentials, 'settings' => $data['settings'] ?? $gateway->settings ?? [],
                'is_active' => (bool) ($data['is_active'] ?? true), 'is_default' => (bool) ($data['is_default'] ?? false),
            ]);
            $gateway->save();

            if ($gateway->is_default) {
                PaymentGateway::query()->whereKeyNot($gateway->id)->update(['is_default' => false]);
            }

            return $gateway;
        });
    }

    public function rotateWebhookToken(PaymentGateway $gateway): void
    {
        $gateway->update(['webhook_token' => Str::random(40)]);
    }

    // ------------------------------------------------------------------ cobrança

    public function createCharge(User $actor, Receivable $receivable, PaymentGateway $gateway, string $billingType, int $amountCents, string $dueDate, string $idempotencyKey): PaymentCharge
    {
        if ($existing = PaymentCharge::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing; // repetição (clique duplo / reenvio) devolve a mesma cobrança
        }
        if (! $gateway->is_active) {
            throw new BusinessRuleViolation('Gateway inativo.', 'gateway_inactive');
        }
        if (! array_key_exists($billingType, PaymentCharge::BILLING_TYPES)) {
            throw new BusinessRuleViolation('Forma de cobrança inválida.', 'invalid_billing_type');
        }
        if (! in_array($receivable->status, ['open', 'partial'], true) || $amountCents <= 0 || $amountCents > $receivable->balanceCents()) {
            throw new BusinessRuleViolation('Valor deve ser entre R$ 0,01 e o saldo da conta ('.Format::money($receivable->balanceCents()).').', 'invalid_amount');
        }
        if (CarbonImmutable::parse($dueDate)->lt(CarbonImmutable::today('America/Sao_Paulo'))) {
            throw new BusinessRuleViolation('Vencimento no passado.', 'invalid_due_date');
        }

        $provider = $this->provider($gateway);
        $patient = $receivable->patient_id ? Patient::query()->find($receivable->patient_id) : null;
        $split = $provider->supportsNativeSplit() ? $this->splits->nativePayload($receivable, $gateway, $amountCents) : null;

        // 1) registra localmente ANTES de chamar o gateway (nunca fica cobrança remota "órfã" sem rastro)
        try {
            $charge = DB::transaction(fn () => PaymentCharge::create([
                'branch_id' => $receivable->branch_id, 'receivable_id' => $receivable->id, 'gateway_id' => $gateway->id,
                'provider' => $gateway->provider, 'mode' => $gateway->mode, 'patient_id' => $patient?->id, 'amount_cents' => $amountCents,
                'billing_type' => $billingType, 'due_date' => $dueDate, 'idempotency_key' => $idempotencyKey,
                'public_token' => Str::random(40), 'split_snapshot' => $split, 'created_by' => $actor->id,
            ]));
        } catch (QueryException $e) {
            return PaymentCharge::query()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }

        // 2) cria no gateway
        try {
            $customerId = $patient ? $this->customerId($gateway, $provider, $patient) : null;
            if ($gateway->provider === 'asaas' && ! $customerId) {
                throw new BusinessRuleViolation('O ASAAS exige um paciente com CPF vinculado à conta.', 'patient_required');
            }

            $result = $provider->createCharge($gateway, new ChargeRequest(
                $charge->id, $amountCents, $billingType, $dueDate,
                mb_substr($receivable->description, 0, 200), $customerId,
                $split ? ['wallet_id' => $split['wallet_id'], 'type' => $split['type'], 'value' => $split['value']] : null,
                (int) $gateway->setting('max_installments', 1),
            ));
        } catch (Throwable $e) {
            $charge->forceFill(['status' => 'failed', 'review_reason' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }

        $charge->forceFill([
            'provider_charge_id' => $result->providerChargeId, 'payment_url' => $result->paymentUrl,
            'pix_payload' => $result->pixPayload, 'pix_qr_image' => $result->pixQrImage,
        ])->save();

        $this->audit->record('payment.charge_created', $receivable, metadata: [
            'charge_id' => $charge->id, 'gateway' => $gateway->provider, 'mode' => $gateway->mode, 'amount_cents' => $amountCents,
            'billing_type' => $billingType, 'native_split' => (bool) $split,
        ]);

        return $charge;
    }

    public function cancelCharge(User $actor, PaymentCharge $charge): PaymentCharge
    {
        if (! $charge->isOpen()) {
            throw new BusinessRuleViolation('Somente cobranças em aberto podem ser canceladas.', 'not_open', 409);
        }

        if ($charge->provider_charge_id) {
            $this->provider($charge->gateway)->cancelCharge($charge->gateway, $charge->provider_charge_id);
        }

        $charge->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $this->audit->record('payment.charge_cancelled', $charge->receivable, metadata: ['charge_id' => $charge->id]);

        return $charge;
    }

    /** Solicita o estorno no gateway; a baixa reversa acontece quando o gateway confirmar. */
    public function refundCharge(User $actor, PaymentCharge $charge): PaymentCharge
    {
        if ($charge->status !== 'paid') {
            throw new BusinessRuleViolation('Somente cobranças pagas podem ser estornadas.', 'not_paid', 409);
        }

        $this->provider($charge->gateway)->refund($charge->gateway, $charge->provider_charge_id);
        $this->audit->record('payment.refund_requested', $charge->receivable, metadata: ['charge_id' => $charge->id, 'by' => $actor->id]);

        return $this->sync($charge);
    }

    // ------------------------------------------------------------------ webhook e sincronização

    /**
     * Webhook do gateway (rota pública, sem sessão). Retorna [httpStatus, mensagem].
     *
     * @return array{0: int, 1: string}
     */
    public function handleWebhook(string $gatewayId, Request $request): array
    {
        $gateway = $this->context->runAsSystem(fn () => PaymentGateway::query()->withoutGlobalScopes()->find($gatewayId));

        if (! $gateway || ! $gateway->is_active) {
            return [404, 'gateway desconhecido'];
        }

        $provider = $this->provider($gateway);
        if (! $provider->verifyWebhook($gateway, $request)) {
            Log::warning('Webhook de pagamento com token inválido', ['gateway' => $gateway->id, 'ip' => $request->ip()]);

            return [401, 'não autorizado'];
        }

        $event = $provider->parseWebhook($request);

        return $this->context->runFor($gateway->company_id, fn () => $this->ingest($gateway, $gateway->provider, $event->eventId, $event->type, $event->providerChargeId, $event->payload));
    }

    /** @return array{0: int, 1: string} */
    private function ingest(PaymentGateway $gateway, string $provider, string $eventId, ?string $type, ?string $chargeId, array $payload): array
    {
        try {
            // Savepoint: no PostgreSQL uma violação de unicidade invalida a transação inteira.
            $event = DB::transaction(fn () => PaymentWebhookEvent::create([
                'company_id' => $gateway->company_id, 'gateway_id' => $gateway->id, 'provider' => $provider,
                'provider_event_id' => mb_substr($eventId, 0, 150), 'event_type' => $type ? mb_substr($type, 0, 80) : null,
                'provider_charge_id' => $chargeId, 'payload' => $payload, 'received_at' => now(),
            ]));
        } catch (QueryException) {
            return [200, 'evento já recebido']; // idempotência: reenvio do gateway
        }

        $charge = $chargeId ? PaymentCharge::query()->where('gateway_id', $gateway->id)->where('provider_charge_id', $chargeId)->first() : null;

        if (! $charge) {
            $event->update(['status' => 'ignored', 'error' => 'Cobrança não encontrada neste sistema', 'processed_at' => now()]);

            return [200, 'ignorado'];
        }

        try {
            $this->sync($charge);
            $event->update(['status' => 'processed', 'processed_at' => now(), 'attempts' => 1]);

            return [200, 'ok'];
        } catch (Throwable $e) {
            // Falha ao consultar o gateway: o evento fica registrado e a sincronização periódica tenta de novo.
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'attempts' => 1]);
            Log::error('Falha ao processar webhook de pagamento', ['charge' => $charge->id, 'error' => $e->getMessage()]);

            return [200, 'registrado para nova tentativa'];
        }
    }

    /** Consulta o gateway (fonte de verdade) e aplica a situação na cobrança e no financeiro. */
    public function sync(PaymentCharge $charge): PaymentCharge
    {
        if (! $charge->provider_charge_id) {
            return $charge;
        }

        $gateway = $charge->gateway()->first();
        $remote = $this->provider($gateway)->fetchCharge($gateway, $charge->provider_charge_id);

        return DB::transaction(function () use ($charge, $remote) {
            $c = PaymentCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();
            $c->last_checked_at = now();
            $this->apply($c, $remote);
            $c->save();

            return $c;
        });
    }

    private function apply(PaymentCharge $c, RemoteCharge $remote): void
    {
        switch (true) {
            case $remote->status === 'paid' && $c->isOpen():
                if ($remote->paidCents !== $c->amount_cents) {
                    $c->status = 'review';
                    $c->review_reason = 'Valor pago no gateway ('.Format::money((int) $remote->paidCents).') diferente do cobrado ('.Format::money($c->amount_cents).'). Confira antes de dar baixa.';
                    $this->audit->record('payment.amount_mismatch', $c->receivable, metadata: ['charge_id' => $c->id, 'paid_cents' => $remote->paidCents, 'amount_cents' => $c->amount_cents]);

                    return;
                }

                // Valores do gateway antes da baixa: o split nativo incide sobre o líquido.
                $c->paid_cents = $remote->paidCents;
                $c->net_cents = $remote->netCents;

                try {
                    $txn = $this->finance->receiveOnline($c, $remote->paidCents, $remote->netCents, $remote->method ?? $this->methodFor($c), $remote->paidAt ?? CarbonImmutable::now());
                } catch (BusinessRuleViolation $e) {
                    $c->status = 'review';
                    $c->review_reason = $e->getMessage();

                    return;
                }

                $c->forceFill(['status' => 'paid', 'paid_cents' => $remote->paidCents, 'net_cents' => $remote->netCents,
                    'paid_at' => $remote->paidAt ?? now(), 'transaction_id' => $txn->id, 'review_reason' => null]);
                $this->audit->record('payment.confirmed', $c->receivable, metadata: ['charge_id' => $c->id, 'amount_cents' => $remote->paidCents, 'transaction_id' => $txn->id]);
                break;

            case $remote->status === 'refunded' && $c->status === 'paid':
                $txn = $c->transaction_id ? FinancialTransaction::query()->find($c->transaction_id) : null;
                if ($txn && ! FinancialTransaction::query()->where('reversal_of', $txn->id)->exists()) {
                    $this->finance->reverse(null, $txn, 'Estorno confirmado pelo gateway '.strtoupper($c->provider));
                }
                $c->status = 'refunded';
                break;

            case $remote->status === 'review' && $c->status !== 'review':
                $c->status = 'review';
                $c->review_reason = 'Situação no gateway exige conferência: '.($remote->detail ?? 'indefinida');
                break;

            case in_array($remote->status, ['overdue', 'cancelled', 'failed'], true) && $c->isOpen():
                $c->status = $remote->status;
                break;
        }
    }

    /** Rotina periódica (cron): cobre webhooks perdidos. */
    public function syncOpenCharges(int $limit = 100): int
    {
        $ids = $this->context->runAsSystem(fn () => PaymentCharge::query()->withoutGlobalScopes()
            ->whereIn('status', ['pending', 'overdue'])->whereNotNull('provider_charge_id')
            ->where('created_at', '>=', now()->subDays(40))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subMinutes(10)))
            ->orderBy('last_checked_at')->limit($limit)->get(['id', 'company_id']));

        foreach ($ids as $row) {
            try {
                $this->context->runFor($row->company_id, fn () => $this->sync(PaymentCharge::query()->findOrFail($row->id)));
            } catch (Throwable $e) {
                Log::warning('Sincronização de cobrança falhou', ['charge' => $row->id, 'error' => $e->getMessage()]);
            }
        }

        return $ids->count();
    }

    /** MOCK: simula a ação do "gateway" e dispara o mesmo fluxo de webhook. */
    public function simulateMock(User $actor, PaymentCharge $charge, string $outcome, ?int $paidCents = null): PaymentCharge
    {
        $gateway = $charge->gateway()->first();
        if (! $gateway->isMock() || $gateway->provider !== 'mock') {
            throw new BusinessRuleViolation('Simulação disponível apenas no gateway MOCK.', 'not_mock', 403);
        }

        /** @var MockProvider $provider */
        $provider = $this->provider($gateway);
        $provider->simulate($charge->provider_charge_id, $outcome, $paidCents, $charge->billing_type === 'credit_card' ? 'credit_card' : ($charge->billing_type === 'boleto' ? 'boleto' : 'pix'));
        $this->ingest($gateway, 'mock', 'mock_evt_'.Str::ulid(), 'MOCK_'.strtoupper($outcome), $charge->provider_charge_id, ['simulated_by' => $actor->id, 'outcome' => $outcome]);

        return $charge->fresh();
    }

    private function customerId(PaymentGateway $gateway, PaymentProvider $provider, Patient $patient): ?string
    {
        $existing = PaymentCustomer::query()->where('gateway_id', $gateway->id)->where('patient_id', $patient->id)->value('provider_customer_id');
        if ($existing) {
            return $existing;
        }

        $id = $provider->ensureCustomer($gateway, $patient);
        if ($id) {
            PaymentCustomer::query()->firstOrCreate(['gateway_id' => $gateway->id, 'patient_id' => $patient->id], ['provider_customer_id' => $id]);
        }

        return $id;
    }

    private function methodFor(PaymentCharge $c): string
    {
        return ['boleto' => 'boleto', 'credit_card' => 'credit_card'][$c->billing_type] ?? 'pix';
    }
}
