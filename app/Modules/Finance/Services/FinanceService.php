<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Services\SplitService;
use App\Modules\Scheduling\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contas a receber/pagar, recebimentos, pagamentos, estornos e caixa.
 *
 * Regras:
 * - valores em centavos; recebimento nunca maior que o saldo;
 * - dinheiro só entra/sai por um CAIXA ABERTO do operador (o que está na gaveta
 *   precisa bater no fechamento); demais formas entram no caixa aberto, se houver;
 * - movimentação é imutável: correção = estorno vinculado (uma única vez);
 * - fechamento cego (o operador declara o contado sem ver o esperado) e
 *   conferência por OUTRO usuário, com justificativa se houver diferença.
 */
class FinanceService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly SplitService $splits,
    ) {}

    // ------------------------------------------------------------------ categorias

    public function ensureCategories(?string $companyId = null): void
    {
        $companyId ??= $this->context->companyId();

        if (DB::table('financial_categories')->where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (FinancialCategory::DEFAULTS as $type => $names) {
            foreach ($names as $name) {
                DB::table('financial_categories')->insertOrIgnore([
                    'id' => (string) Str::ulid(), 'company_id' => $companyId, 'type' => $type, 'name' => $name,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function defaultCategory(string $type, string $name): string
    {
        $this->ensureCategories();

        return FinancialCategory::query()->where('type', $type)->where('name', $name)->value('id')
            ?? FinancialCategory::query()->where('type', $type)->orderBy('created_at')->value('id');
    }

    // ------------------------------------------------------------------ contas a receber

    public function createReceivable(User $actor, array $data): Receivable
    {
        $this->assertCategory($data['category_id'], 'income');
        $this->assertBranch($data['branch_id']);

        $receivable = Receivable::create([
            'branch_id' => $data['branch_id'], 'category_id' => $data['category_id'], 'patient_id' => $data['patient_id'] ?? null,
            'doctor_id' => $data['doctor_id'] ?? null, 'description' => $data['description'], 'amount_cents' => $data['amount_cents'],
            'due_date' => $data['due_date'], 'notes' => $data['notes'] ?? null, 'payer_type' => $data['payer_type'] ?? 'private',
            'origin' => 'manual', 'created_by' => $actor->id,
        ]);

        return $receivable;
    }

    /** Conta a receber gerada na chegada do paciente (particular com valor). Idempotente. */
    public function receivableForAppointment(Appointment $appointment, ?User $actor = null): ?Receivable
    {
        if ($appointment->payer_type !== 'private' || $appointment->price_cents <= 0) {
            return null;
        }

        if ($existing = Receivable::query()->where('appointment_id', $appointment->id)->first()) {
            return $existing;
        }

        $appointment->loadMissing(['service:id,name', 'doctor:id,name,social_name', 'branch:id,timezone']);
        $tz = $appointment->branch->timezone ?: 'America/Sao_Paulo';

        try {
            return DB::transaction(fn () => Receivable::create([
                'branch_id' => $appointment->branch_id,
                'category_id' => $this->defaultCategory('income', 'Consultas'),
                'patient_id' => $appointment->patient_id, 'appointment_id' => $appointment->id, 'doctor_id' => $appointment->doctor_id,
                'description' => mb_substr(($appointment->service?->name ?? 'Consulta').' — '.$appointment->doctor->displayName()
                    .' — '.$appointment->starts_at->timezone($tz)->format('d/m/Y H:i'), 0, 200),
                'amount_cents' => $appointment->price_cents,
                'due_date' => $appointment->starts_at->timezone($tz)->toDateString(),
                'origin' => 'appointment', 'payer_type' => 'private', 'created_by' => $actor?->id,
            ]));
        } catch (QueryException $e) {
            // Corrida entre duas chegadas simultâneas: o índice único (empresa, agendamento) garante um só.
            return Receivable::query()->where('appointment_id', $appointment->id)->first() ?? throw $e;
        }
    }

    /** Agendamento cancelado/falta: cancela a cobrança ainda não paga. */
    public function cancelAppointmentReceivable(Appointment $appointment, User $actor, string $reason): void
    {
        $r = Receivable::query()->where('appointment_id', $appointment->id)->where('status', 'open')->where('paid_cents', 0)->first();

        if ($r) {
            $this->cancelReceivable($actor, $r, $reason);
        }
    }

    /**
     * Recebe (total ou parcial) uma conta.
     *
     * @param  array{amount_cents: int, method: string, discount_cents?: int, card_installments?: ?int, card_brand?: ?string, authorization_code?: ?string, paid_on?: ?string}  $data
     */
    public function receive(User $actor, Receivable $receivable, array $data, bool $allowDiscount = false): ?FinancialTransaction
    {
        $this->assertMethod($data['method']);
        $discount = (int) ($data['discount_cents'] ?? 0);

        if ($discount > 0 && ! $allowDiscount) {
            throw new BusinessRuleViolation('Você não tem permissão para conceder desconto.', 'discount_forbidden', 403);
        }

        return DB::transaction(function () use ($actor, $receivable, $data, $discount) {
            $r = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if (! in_array($r->status, ['open', 'partial'], true)) {
                throw new BusinessRuleViolation('Conta '.mb_strtolower(Receivable::STATUSES[$r->status]).' — não há saldo a receber.', 'not_receivable', 409);
            }

            $amount = (int) $data['amount_cents'];
            $balance = $r->amount_cents - $r->discount_cents - $r->paid_cents - $discount;

            if ($discount < 0 || $balance < 0) {
                throw new BusinessRuleViolation('Desconto maior que o saldo da conta.', 'invalid_discount');
            }
            if ($amount <= 0 && $discount === 0) {
                throw new BusinessRuleViolation('Informe o valor recebido.', 'invalid_amount');
            }
            if ($amount > $balance) {
                throw new BusinessRuleViolation('Valor maior que o saldo ('.Format::money($balance).'). Para dinheiro, informe o valor da conta e devolva o troco.', 'amount_exceeds_balance');
            }

            $session = $this->sessionFor($actor, $data['method']);
            $txn = null;

            if ($amount > 0) {
                $txn = $this->record($actor, [
                    'branch_id' => $session?->branch_id ?? $r->branch_id, 'direction' => 'in', 'kind' => 'receipt', 'method' => $data['method'],
                    'amount_cents' => $amount, 'receivable_id' => $r->id, 'cash_session_id' => $session?->id,
                    'card_installments' => $data['card_installments'] ?? null, 'card_brand' => $data['card_brand'] ?? null,
                    'authorization_code' => $data['authorization_code'] ?? null, 'description' => $r->description,
                    'occurred_at' => $this->occurredAt($session, $data['paid_on'] ?? null),
                ]);
            }

            $r->discount_cents += $discount;
            $r->paid_cents += $amount;
            $this->refreshStatus($r);
            Receivable::withoutAuditing(fn () => $r->save());

            if ($txn) {
                $this->splits->applyForReceipt($txn);
            }

            $this->audit->record('finance.received', $r, metadata: [
                'amount_cents' => $amount, 'discount_cents' => $discount, 'method' => $data['method'], 'transaction_id' => $txn?->id, 'cash_session_id' => $session?->id,
            ]);

            return $txn; // null = apenas desconto aplicado
        });
    }

    /**
     * Baixa de cobrança online CONFIRMADA pelo gateway (chamado só pelo PaymentService, após
     * webhook autenticado + consulta à API). Registra a tarifa do gateway como saída.
     */
    public function receiveOnline(PaymentCharge $charge, int $paidCents, ?int $netCents, string $method, CarbonImmutable $paidAt): FinancialTransaction
    {
        $r = Receivable::query()->whereKey($charge->receivable_id)->lockForUpdate()->firstOrFail();

        if (! in_array($r->status, ['open', 'partial'], true) || $paidCents > $r->amount_cents - $r->discount_cents - $r->paid_cents) {
            throw new BusinessRuleViolation('Conta já quitada ou saldo menor que o valor pago online — revisar (possível pagamento em duplicidade).', 'duplicate_payment', 409);
        }

        $txn = $this->record(null, [
            'branch_id' => $r->branch_id, 'direction' => 'in', 'kind' => 'receipt', 'method' => $method, 'amount_cents' => $paidCents,
            'receivable_id' => $r->id, 'gateway' => $charge->provider, 'gateway_reference' => $charge->provider_charge_id,
            'description' => $r->description, 'occurred_at' => $paidAt->min(CarbonImmutable::now()),
        ]);

        if ($netCents !== null && $netCents < $paidCents) {
            $this->record(null, [
                'branch_id' => $r->branch_id, 'direction' => 'out', 'kind' => 'fee', 'method' => $method, 'amount_cents' => $paidCents - $netCents,
                'receivable_id' => $r->id, 'gateway' => $charge->provider, 'gateway_reference' => $charge->provider_charge_id,
                'description' => 'Tarifa do gateway '.strtoupper($charge->provider), 'occurred_at' => $paidAt->min(CarbonImmutable::now()),
            ]);
        }

        $r->paid_cents += $paidCents;
        $this->refreshStatus($r);
        Receivable::withoutAuditing(fn () => $r->save());

        $this->splits->applyForReceipt($txn, $charge);
        $this->audit->record('finance.received', $r, metadata: ['amount_cents' => $paidCents, 'method' => $method, 'transaction_id' => $txn->id, 'gateway' => $charge->provider, 'charge_id' => $charge->id]);

        return $txn;
    }

    public function cancelReceivable(User $actor, Receivable $receivable, string $reason): Receivable
    {
        return DB::transaction(function () use ($actor, $receivable, $reason) {
            $r = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if ($r->status === 'cancelled') {
                throw new BusinessRuleViolation('Conta já cancelada.', 'already_cancelled', 409);
            }
            if ($r->paid_cents > 0) {
                throw new BusinessRuleViolation('Conta com recebimentos: estorne os recebimentos antes de cancelar.', 'has_payments', 409);
            }

            $r->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => mb_substr($reason, 0, 255)]);
            $r->save();

            return $r;
        });
    }

    // ------------------------------------------------------------------ contas a pagar

    /** Cria a conta (ou N parcelas mensais). @return Collection<int, Payable> */
    public function createPayable(User $actor, array $data): Collection
    {
        $this->assertCategory($data['category_id'], 'expense');
        if (! empty($data['branch_id'])) {
            $this->assertBranch($data['branch_id']);
        }

        $n = max(1, min(60, (int) ($data['installments'] ?? 1)));
        $total = (int) $data['amount_cents'];
        $each = intdiv($total, $n);
        $group = $n > 1 ? (string) Str::ulid() : null;
        $first = CarbonImmutable::parse($data['due_date']);

        return DB::transaction(fn () => collect(range(1, $n))->map(fn ($i) => Payable::create([
            'branch_id' => $data['branch_id'] ?? null, 'category_id' => $data['category_id'], 'supplier' => $data['supplier'],
            'description' => $data['description'].($n > 1 ? " ({$i}/{$n})" : ''), 'document_number' => $data['document_number'] ?? null,
            // A última parcela absorve os centavos da divisão.
            'amount_cents' => $i === $n ? $total - $each * ($n - 1) : $each,
            'due_date' => $first->addMonthsNoOverflow($i - 1)->toDateString(),
            'installment_group' => $group, 'installment' => $i, 'installments' => $n,
            'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
        ])));
    }

    public function pay(User $actor, Payable $payable, array $data): FinancialTransaction
    {
        $this->assertMethod($data['method']);

        return DB::transaction(function () use ($actor, $payable, $data) {
            $p = Payable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();

            if (! in_array($p->status, ['open', 'partial'], true)) {
                throw new BusinessRuleViolation('Conta sem saldo a pagar.', 'not_payable', 409);
            }

            $amount = (int) $data['amount_cents'];
            if ($amount <= 0 || $amount > $p->balanceCents()) {
                throw new BusinessRuleViolation('Valor inválido: deve ser entre R$ 0,01 e o saldo ('.Format::money($p->balanceCents()).').', 'invalid_amount');
            }

            $session = $this->sessionFor($actor, $data['method']);
            if ($session && $data['method'] === 'cash') {
                $this->ensureCashAvailable($session, $amount);
            }

            $txn = $this->record($actor, [
                'branch_id' => $session?->branch_id ?? $p->branch_id ?? $this->fallbackBranch(), 'direction' => 'out', 'kind' => 'payment',
                'method' => $data['method'], 'amount_cents' => $amount, 'payable_id' => $p->id, 'cash_session_id' => $session?->id,
                'authorization_code' => $data['authorization_code'] ?? null, 'description' => $p->supplier.' — '.$p->description,
                'occurred_at' => $this->occurredAt($session, $data['paid_on'] ?? null),
            ]);

            $p->paid_cents += $amount;
            $p->status = $p->paid_cents >= $p->amount_cents ? 'paid' : 'partial';
            $p->paid_at = $p->status === 'paid' ? now() : null;
            Payable::withoutAuditing(fn () => $p->save());
            $this->audit->record('finance.paid', $p, metadata: ['amount_cents' => $amount, 'method' => $data['method'], 'transaction_id' => $txn->id]);

            return $txn;
        });
    }

    public function cancelPayable(User $actor, Payable $payable, string $reason): Payable
    {
        return DB::transaction(function () use ($actor, $payable, $reason) {
            $p = Payable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();

            if ($p->status === 'cancelled') {
                throw new BusinessRuleViolation('Conta já cancelada.', 'already_cancelled', 409);
            }
            if ($p->paid_cents > 0) {
                throw new BusinessRuleViolation('Conta com pagamentos: estorne os pagamentos antes de cancelar.', 'has_payments', 409);
            }

            $p->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => mb_substr($reason, 0, 255)])->save();

            return $p;
        });
    }

    // ------------------------------------------------------------------ estorno

    public function reverse(?User $actor, FinancialTransaction $original, string $reason): FinancialTransaction
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new BusinessRuleViolation('Informe o motivo do estorno (mínimo 10 caracteres).', 'reason_required');
        }

        return DB::transaction(function () use ($actor, $original, $reason) {
            $o = FinancialTransaction::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($o->gateway && $actor !== null) {
                throw new BusinessRuleViolation('Recebimento online: use "Estornar no gateway" na cobrança (o dinheiro precisa voltar pelo gateway).', 'use_gateway_refund');
            }
            if ($o->kind === 'fee') {
                throw new BusinessRuleViolation('Tarifa do gateway é lançada automaticamente e não pode ser estornada.', 'fee_not_reversible');
            }
            if ($o->kind === 'reversal') {
                throw new BusinessRuleViolation('Um estorno não pode ser estornado — lance a operação novamente.', 'reversal_of_reversal');
            }
            if (FinancialTransaction::query()->where('reversal_of', $o->id)->exists()) {
                throw new BusinessRuleViolation('Movimentação já estornada.', 'already_reversed', 409);
            }

            // Dinheiro precisa sair/voltar fisicamente da gaveta de um caixa aberto.
            $session = $this->sessionFor($actor, $o->method);
            if ($session && $o->method === 'cash' && $o->direction === 'in') {
                $this->ensureCashAvailable($session, $o->amount_cents);
            }

            $reversal = $this->record($actor, [
                'branch_id' => $session?->branch_id ?? $o->branch_id, 'direction' => $o->direction === 'in' ? 'out' : 'in', 'kind' => 'reversal',
                'method' => $o->method, 'amount_cents' => $o->amount_cents, 'receivable_id' => $o->receivable_id, 'payable_id' => $o->payable_id,
                'cash_session_id' => $session?->id, 'reversal_of' => $o->id, 'description' => 'Estorno: '.mb_substr(trim($reason), 0, 230),
                'occurred_at' => now(),
            ]);

            if ($o->receivable_id) {
                $r = Receivable::query()->whereKey($o->receivable_id)->lockForUpdate()->first();
                $r->paid_cents -= $o->amount_cents;
                $this->refreshStatus($r);
                Receivable::withoutAuditing(fn () => $r->save());
            }
            if ($o->payable_id) {
                $p = Payable::query()->whereKey($o->payable_id)->lockForUpdate()->first();
                $p->paid_cents -= $o->amount_cents;
                $p->status = $p->paid_cents === 0 ? 'open' : 'partial';
                $p->paid_at = null;
                Payable::withoutAuditing(fn () => $p->save());
            }

            $this->splits->reverseFor($reversal);
            $this->audit->record('finance.reversed', $o, metadata: ['reversal_id' => $reversal->id, 'amount_cents' => $o->amount_cents, 'method' => $o->method, 'reason' => trim($reason)]);

            return $reversal;
        });
    }

    // ------------------------------------------------------------------ caixa

    public function openSession(User $actor, string $branchId, int $openingCents): CashSession
    {
        $this->assertBranch($branchId);

        if ($openingCents < 0) {
            throw new BusinessRuleViolation('Fundo de troco inválido.', 'invalid_amount');
        }
        if ($this->openSessionOf($actor)) {
            throw new BusinessRuleViolation('Você já tem um caixa aberto. Feche-o antes de abrir outro.', 'session_already_open', 409);
        }

        try {
            $session = DB::transaction(fn () => CashSession::create(['branch_id' => $branchId, 'user_id' => $actor->id, 'opened_at' => now(), 'opening_cents' => $openingCents]));
        } catch (QueryException) {
            throw new BusinessRuleViolation('Você já tem um caixa aberto.', 'session_already_open', 409);
        }

        $this->audit->record('cash.opened', $session, metadata: ['opening_cents' => $openingCents, 'branch_id' => $branchId]);

        return $session;
    }

    public function openSessionOf(User $actor): ?CashSession
    {
        return CashSession::query()->where('user_id', $actor->id)->where('status', 'open')->first();
    }

    /** Sangria (retirada) ou suprimento (reforço de troco). */
    public function movement(User $actor, CashSession $session, string $kind, int $amount, string $reason): FinancialTransaction
    {
        if (! in_array($kind, ['withdrawal', 'deposit'], true) || $amount <= 0 || mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleViolation('Informe valor e motivo.', 'invalid_movement');
        }

        return DB::transaction(function () use ($actor, $session, $kind, $amount, $reason) {
            $s = $this->lockOwnOpenSession($actor, $session);

            if ($kind === 'withdrawal') {
                $this->ensureCashAvailable($s, $amount);
            }

            $txn = $this->record($actor, [
                'branch_id' => $s->branch_id, 'direction' => $kind === 'withdrawal' ? 'out' : 'in', 'kind' => $kind, 'method' => 'cash',
                'amount_cents' => $amount, 'cash_session_id' => $s->id, 'description' => mb_substr(trim($reason), 0, 255), 'occurred_at' => now(),
            ]);
            $this->audit->record('cash.'.$kind, $s, metadata: ['amount_cents' => $amount, 'transaction_id' => $txn->id]);

            return $txn;
        });
    }

    /**
     * Resumo do caixa: por forma de pagamento (entradas, saídas, líquido) e dinheiro esperado na gaveta.
     *
     * @return array{methods: array<string, array{in: int, out: int, net: int}>, cash_expected: int, total_in: int, total_out: int}
     */
    public function sessionSummary(CashSession $session): array
    {
        $rows = DB::table('financial_transactions')->where('cash_session_id', $session->id)
            ->selectRaw("method, SUM(CASE WHEN direction = 'in' THEN amount_cents ELSE 0 END) AS total_in, SUM(CASE WHEN direction = 'out' THEN amount_cents ELSE 0 END) AS total_out")
            ->groupBy('method')->get();

        $methods = [];
        foreach ($rows as $row) {
            $methods[$row->method] = ['in' => (int) $row->total_in, 'out' => (int) $row->total_out, 'net' => (int) $row->total_in - (int) $row->total_out];
        }

        return [
            'methods' => $methods,
            'cash_expected' => $session->opening_cents + ($methods['cash']['net'] ?? 0),
            'total_in' => array_sum(array_column($methods, 'in')),
            'total_out' => array_sum(array_column($methods, 'out')),
        ];
    }

    /**
     * Fechamento cego: o operador informa o que contou por forma de pagamento.
     *
     * @param  array<string, int>  $declared  centavos por forma (dinheiro = total na gaveta, incluindo o troco)
     */
    public function closeSession(User $actor, CashSession $session, array $declared, ?string $notes = null): CashSession
    {
        return DB::transaction(function () use ($actor, $session, $declared, $notes) {
            $s = $this->lockOwnOpenSession($actor, $session);
            $summary = $this->sessionSummary($s);

            $expected = ['cash' => $summary['cash_expected']];
            foreach ($summary['methods'] as $method => $m) {
                if ($method !== 'cash') {
                    $expected[$method] = $m['net'];
                }
            }

            $clean = [];
            foreach (array_keys(FinancialTransaction::METHODS) as $method) {
                if (array_key_exists($method, $declared) || array_key_exists($method, $expected)) {
                    $clean[$method] = max(0, (int) ($declared[$method] ?? 0));
                    $expected[$method] ??= 0;
                }
            }

            $difference = 0;
            foreach ($expected as $method => $value) {
                $difference += $clean[$method] - $value;
            }

            $s->forceFill([
                'status' => 'closed', 'closed_at' => now(), 'expected' => $expected, 'declared' => $clean,
                'difference_cents' => $difference, 'closing_notes' => $notes ? mb_substr(trim($notes), 0, 500) : null,
            ])->save();

            $this->audit->record('cash.closed', $s, metadata: ['expected' => $expected, 'declared' => $clean, 'difference_cents' => $difference]);

            return $s;
        });
    }

    /** Conferência por um supervisor (nunca o próprio operador). */
    public function reviewSession(User $actor, CashSession $session, ?string $notes): CashSession
    {
        return DB::transaction(function () use ($actor, $session, $notes) {
            $s = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($s->status !== 'closed') {
                throw new BusinessRuleViolation('Somente caixas fechados podem ser conferidos.', 'not_closed', 409);
            }
            if ($s->user_id === $actor->id) {
                throw new BusinessRuleViolation('A conferência deve ser feita por outra pessoa (segregação de funções).', 'self_review', 403);
            }
            if ($s->difference_cents !== 0 && mb_strlen(trim((string) $notes)) < 10) {
                throw new BusinessRuleViolation('Caixa com diferença de '.Format::money($s->difference_cents).': registre a justificativa/providência (mínimo 10 caracteres).', 'justification_required');
            }

            $s->forceFill(['status' => 'reviewed', 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_notes' => $notes ? mb_substr(trim($notes), 0, 500) : null])->save();
            $this->audit->record('cash.reviewed', $s, metadata: ['difference_cents' => $s->difference_cents]);

            return $s;
        });
    }

    // ------------------------------------------------------------------ relatórios

    /**
     * Fluxo de caixa realizado no período (estornos já compensam o original).
     *
     * @return array{in: int, out: int, net: int, by_method: array, by_category: array, by_day: array}
     */
    public function cashFlow(string $from, string $to, ?string $branchId = null, string $tz = 'America/Sao_Paulo'): array
    {
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();

        $txns = FinancialTransaction::query()->with(['receivable:id,category_id', 'payable:id,category_id'])
            ->accessibleBranches($this->context->allowedBranchIds())
            ->whereBetween('occurred_at', [$start, $end])->when($branchId, fn ($q, $b) => $q->where('branch_id', $b))
            ->whereIn('kind', ['receipt', 'payment', 'reversal', 'fee'])->get();
        $categories = FinancialCategory::query()->pluck('name', 'id');

        $byMethod = $byCategory = $byDay = [];
        foreach ($txns as $t) {
            $signed = $t->signedCents();
            $byMethod[$t->method] = ($byMethod[$t->method] ?? 0) + $signed;
            $cat = $t->kind === 'fee' ? 'Tarifas bancárias e de cartão' : ($categories[$t->receivable?->category_id ?? $t->payable?->category_id] ?? 'Sem categoria');
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + $signed;
            $day = $t->occurred_at->timezone($tz)->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + $signed;
        }
        ksort($byDay);
        arsort($byCategory);

        $in = $txns->sum(fn ($t) => $t->signedCents() > 0 ? $t->amount_cents : 0);
        $out = $txns->sum(fn ($t) => $t->signedCents() < 0 ? $t->amount_cents : 0);

        return ['in' => $in, 'out' => $out, 'net' => $in - $out, 'by_method' => $byMethod, 'by_category' => $byCategory, 'by_day' => $byDay];
    }

    // ------------------------------------------------------------------ internos

    private function record(?User $actor, array $data): FinancialTransaction
    {
        return FinancialTransaction::create($data + ['created_by' => $actor?->id]);
    }

    private function refreshStatus(Receivable $r): void
    {
        $r->status = $r->discount_cents + $r->paid_cents >= $r->amount_cents ? 'paid' : ($r->paid_cents > 0 || $r->discount_cents > 0 ? 'partial' : 'open');
        $r->paid_at = $r->status === 'paid' ? now() : null;
    }

    /** Caixa aberto do operador: obrigatório para dinheiro; usado nas demais formas quando existir. */
    private function sessionFor(?User $actor, string $method): ?CashSession
    {
        if (! $actor) {
            return null; // lançamento automático (gateway): nunca passa por caixa
        }

        $session = CashSession::query()->where('user_id', $actor->id)->where('status', 'open')->lockForUpdate()->first();

        if (! $session && $method === 'cash') {
            throw new BusinessRuleViolation('Abra o seu caixa para movimentar dinheiro.', 'cash_session_required');
        }

        return $session;
    }

    private function lockOwnOpenSession(User $actor, CashSession $session): CashSession
    {
        $s = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

        if ($s->user_id !== $actor->id) {
            throw new BusinessRuleViolation('Somente o operador do caixa pode movimentá-lo ou fechá-lo.', 'not_session_owner', 403);
        }
        if (! $s->isOpen()) {
            throw new BusinessRuleViolation('Caixa já fechado.', 'session_closed', 409);
        }

        return $s;
    }

    private function ensureCashAvailable(CashSession $session, int $amount): void
    {
        $available = $this->sessionSummary($session)['cash_expected'];

        if ($amount > $available) {
            throw new BusinessRuleViolation('Dinheiro insuficiente no caixa (disponível '.Format::money($available).').', 'insufficient_cash');
        }
    }

    /** Lançamentos sem caixa podem ter data retroativa (até 60 dias), nunca futura. */
    private function occurredAt(?CashSession $session, ?string $paidOn): CarbonImmutable
    {
        if ($session || ! $paidOn) {
            return CarbonImmutable::now();
        }

        $date = CarbonImmutable::parse($paidOn, 'America/Sao_Paulo')->setTimeFrom(CarbonImmutable::now('America/Sao_Paulo'));
        if ($date->isFuture() && ! $date->isToday()) {
            throw new BusinessRuleViolation('Data de pagamento no futuro.', 'invalid_date');
        }
        if ($date->lt(CarbonImmutable::now()->subDays(60))) {
            throw new BusinessRuleViolation('Data de pagamento anterior a 60 dias.', 'invalid_date');
        }

        return $date->utc()->min(CarbonImmutable::now());
    }

    private function assertMethod(string $method): void
    {
        if (! array_key_exists($method, FinancialTransaction::METHODS)) {
            throw new BusinessRuleViolation('Forma de pagamento inválida.', 'invalid_method');
        }
    }

    private function assertCategory(string $id, string $type): void
    {
        if (! FinancialCategory::query()->whereKey($id)->where('type', $type)->exists()) {
            throw new BusinessRuleViolation('Categoria inválida.', 'invalid_category');
        }
    }

    private function assertBranch(string $branchId): void
    {
        if (! Branch::query()->accessible($this->context->allowedBranchIds())->whereKey($branchId)->exists()) {
            throw new BusinessRuleViolation('Unidade inválida.', 'invalid_branch');
        }
    }

    private function fallbackBranch(): string
    {
        return $this->context->branchId() ?? Branch::query()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->value('id');
    }
}
