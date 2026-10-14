<?php

namespace App\Modules\Sprint\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Sprint\Models\SplitMath;
use App\Modules\Sprint\Models\SprintPayment;
use App\Modules\Sprint\Models\SprintProfessional;
use App\Modules\Sprint\Models\SprintSale;
use App\Modules\Sprint\Models\SprintSaleItem;
use App\Modules\Sprint\Models\SprintSetting;
use App\Modules\Sprint\Models\SprintSplit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Melhor Sprint: vendas com divisão entre profissionais e clínica, pagamentos mistos
 * (dinheiro, PIX, crédito, débito — ASAAS, Cielo online, maquininha) e repasses.
 *
 * Toda venda é lastreada por uma conta a receber do financeiro (origem "sprint"), então caixa,
 * livro, recibos, conciliação e relatórios continuam valendo. Pagamento online só é baixado
 * depois da confirmação do gateway (webhook autenticado + consulta), como no resto do sistema.
 */
class SprintService
{
    /** Formas × canais aceitos. */
    public const CHANNELS_BY_METHOD = [
        'cash' => ['cash'],
        'pix' => ['asaas', 'mock'],
        'credit_card' => ['cielo_terminal', 'terminal', 'cielo_api', 'asaas', 'mock'],
        'debit_card' => ['cielo_terminal', 'terminal'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FinanceService $finance,
        private readonly PaymentService $payments,
        private readonly SprintLedger $ledger,
        private readonly CommissionService $commission,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ vendas

    /**
     * @param  array{branch_id?: ?string, patient_id?: ?string, customer_name?: ?string, customer_document?: ?string, description?: ?string,
     *     issue_nfse?: bool, items: list<array{professional_id?: ?string, description: string, amount_cents: int, split_type?: ?string, split_value?: ?int}>}  $data
     */
    public function createSale(User $actor, array $data): SprintSale
    {
        $items = $this->buildItems($data['items'] ?? []);
        $total = array_sum(array_column($items, 'amount_cents'));
        $professional = array_sum(array_column($items, 'professional_cents'));
        $branchId = $data['branch_id'] ?? null ?: $this->defaultBranch();

        if (! Branch::query()->active()->accessible($this->context->allowedBranchIds())->whereKey($branchId)->exists()) {
            throw new BusinessRuleViolation('Unidade inválida.', 'invalid_branch');
        }
        if ($total > 99_999_999_99) {
            throw new BusinessRuleViolation('Valor total muito alto.', 'invalid_amount');
        }

        $patient = ! empty($data['patient_id']) ? Patient::query()->findOrFail($data['patient_id']) : null;
        $description = trim((string) ($data['description'] ?? '')) ?: mb_substr(collect($items)->pluck('description')->implode(', '), 0, 200);

        return DB::transaction(function () use ($actor, $data, $items, $total, $professional, $branchId, $patient, $description) {
            $receivable = Receivable::create([
                'branch_id' => $branchId, 'category_id' => $this->finance->defaultCategory('income', 'Procedimentos'),
                'patient_id' => $patient?->id, 'description' => mb_substr('Melhor Sprint — '.$description, 0, 200), 'amount_cents' => $total,
                'due_date' => CarbonImmutable::today('America/Sao_Paulo')->toDateString(), 'origin' => 'sprint', 'payer_type' => 'private',
                'created_by' => $actor->id,
            ]);

            $sale = $this->newSale([
                'branch_id' => $branchId, 'patient_id' => $patient?->id,
                'customer_name' => $patient ? null : (trim((string) ($data['customer_name'] ?? '')) ?: null),
                'customer_document' => $patient ? null : Format::digits($data['customer_document'] ?? null),
                'description' => mb_substr($description, 0, 200), 'receivable_id' => $receivable->id, 'total_cents' => $total,
                'professional_cents' => $professional, 'house_cents' => $total - $professional,
                'commission_bp' => $this->commission->rateFor($this->context->companyId()),
                'issue_nfse' => (bool) ($data['issue_nfse'] ?? false), 'created_by' => $actor->id,
            ]);

            foreach ($items as $item) {
                SprintSaleItem::create($item + ['sale_id' => $sale->id]);
            }

            $this->audit->record('sprint.sale_created', $sale, metadata: ['total_cents' => $total, 'professional_cents' => $professional, 'items' => count($items)]);

            return $sale;
        });
    }

    public function cancelSale(User $actor, SprintSale $sale, string $reason): SprintSale
    {
        return DB::transaction(function () use ($actor, $sale, $reason) {
            $s = SprintSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (! in_array($s->status, ['open', 'refunded'], true)) {
                throw new BusinessRuleViolation('Venda com pagamento: estorne os pagamentos antes de cancelar.', 'has_payments', 409);
            }
            foreach (SprintPayment::query()->with('charge')->where('sale_id', $s->id)->where('status', 'pending')->get() as $p) {
                if ($p->charge?->isOpen()) {
                    $this->payments->cancelCharge($actor, $p->charge);
                }
                $p->forceFill(['status' => 'cancelled'])->save();
            }

            $receivable = Receivable::query()->findOrFail($s->receivable_id);
            if ($receivable->status !== 'cancelled') {
                $this->finance->cancelReceivable($actor, $receivable, 'Venda Melhor Sprint '.$s->code().' cancelada: '.$reason);
            }

            $s->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
            $this->audit->record('sprint.sale_cancelled', $s, metadata: ['reason' => $reason]);

            return $s;
        });
    }

    /**
     * Estorna todos os pagamentos confirmados: dinheiro/maquininha na hora (lançamento de estorno);
     * online pelo gateway (a baixa reversa acontece quando o gateway confirmar).
     *
     * @return array{reversed: int, requested: int}
     */
    public function refundSale(User $actor, SprintSale $sale, string $reason): array
    {
        $counts = ['reversed' => 0, 'requested' => 0];

        foreach (SprintPayment::query()->with('charge')->where('sale_id', $sale->id)->where('status', 'paid')->get() as $p) {
            if ($p->charge_id) {
                $this->payments->refundCharge($actor, $p->charge);
                $counts['requested']++;

                continue;
            }

            $txn = FinancialTransaction::query()->findOrFail($p->transaction_id);
            $this->finance->reverse($actor, $txn, 'Estorno Melhor Sprint '.$sale->code().': '.$reason);
            $counts['reversed']++;
        }

        if ($counts['reversed'] + $counts['requested'] === 0) {
            throw new BusinessRuleViolation('Nenhum pagamento confirmado para estornar.', 'nothing_to_refund', 409);
        }
        $this->audit->record('sprint.sale_refund', $sale, metadata: $counts + ['reason' => $reason]);

        return $counts;
    }

    // ------------------------------------------------------------------ pagamentos

    /**
     * Registra um pagamento (total ou parcial). Dinheiro e maquininha são confirmados na hora;
     * PIX e cartão online geram a cobrança no gateway e aguardam a confirmação.
     *
     * @param  array{method: string, channel: string, amount_cents: int, idempotency_key: string, card_brand?: ?string, installments?: ?int,
     *     nsu?: ?string, terminal_split?: bool, cash_given_cents?: ?int, gateway_id?: ?string}  $data
     */
    public function addPayment(User $actor, SprintSale $sale, array $data): SprintPayment
    {
        $key = $data['idempotency_key'];
        if ($existing = SprintPayment::query()->where('idempotency_key', $key)->first()) {
            return $existing; // clique duplo / reenvio
        }

        $method = $data['method'];
        $channel = $data['channel'];
        if (! in_array($channel, self::CHANNELS_BY_METHOD[$method] ?? [], true)) {
            throw new BusinessRuleViolation(match (true) {
                $method === 'debit_card' => 'Débito: registre a venda feita na maquininha (Cielo ou outra). Débito online não é suportado pelos gateways.',
                $method === 'pix' => 'PIX: use o ASAAS (QR Code na tela).',
                default => 'Forma de pagamento e canal incompatíveis.',
            }, 'invalid_channel');
        }

        $amount = (int) $data['amount_cents'];
        $online = in_array($channel, ['asaas', 'cielo_api', 'mock'], true);
        $terminalSplit = $channel === 'cielo_terminal' && ! empty($data['terminal_split']);

        if (in_array($channel, ['cielo_terminal', 'terminal'], true) && trim((string) ($data['nsu'] ?? '')) === '') {
            throw new BusinessRuleViolation('Informe o NSU / código de autorização do comprovante da maquininha.', 'nsu_required');
        }

        $gateway = $online ? $this->gatewayFor($channel, $data['gateway_id'] ?? null) : null;

        // 1) reserva o valor e grava a divisão planejada (trava a venda: pagamentos simultâneos não estouram o saldo)
        $payment = DB::transaction(function () use ($actor, $sale, $data, $method, $channel, $amount, $terminalSplit, $key) {
            $s = SprintSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $s->isOpen()) {
                throw new BusinessRuleViolation('Venda '.mb_strtolower($s->statusLabel()).' — não aceita novos pagamentos.', 'sale_closed', 409);
            }

            $reserved = (int) $this->ledger->activePayments($s)->sum('amount_cents');
            $available = $s->total_cents - $reserved;
            if ($amount <= 0 || $amount > $available) {
                throw new BusinessRuleViolation('Valor deve ser entre R$ 0,01 e o saldo disponível ('.Format::money(max(0, $available)).'). Cobranças em aberto reservam o valor até serem pagas ou canceladas.', 'invalid_amount');
            }

            $change = null;
            if ($method === 'cash' && isset($data['cash_given_cents'])) {
                if ((int) $data['cash_given_cents'] < $amount) {
                    throw new BusinessRuleViolation('Valor entregue menor que o valor a receber.', 'cash_given_too_low');
                }
                $change = (int) $data['cash_given_cents'] - $amount;
            }

            try {
                return DB::transaction(fn () => SprintPayment::create([
                    'sale_id' => $s->id, 'method' => $method, 'channel' => $channel, 'amount_cents' => $amount,
                    'allocation' => $this->ledger->allocate($s, $amount, $channel, $terminalSplit), 'card_brand' => $data['card_brand'] ?? null,
                    'installments' => $method === 'credit_card' ? max(1, (int) ($data['installments'] ?? 1)) : null,
                    'nsu' => isset($data['nsu']) ? mb_substr(trim($data['nsu']), 0, 60) : null, 'terminal_split' => $terminalSplit,
                    'cash_given_cents' => $method === 'cash' ? ($data['cash_given_cents'] ?? null) : null, 'change_cents' => $change,
                    'idempotency_key' => $key, 'created_by' => $actor->id,
                ]));
            } catch (QueryException $e) {
                return SprintPayment::query()->where('idempotency_key', $key)->first() ?? throw $e;
            }
        });

        if (! $payment->wasRecentlyCreated) {
            return $payment;
        }

        return $online ? $this->startOnline($actor, $sale, $payment, $gateway) : $this->confirmOffline($actor, $sale, $payment);
    }

    /** Dinheiro e maquininha: baixa imediata no financeiro (dinheiro exige caixa aberto). */
    private function confirmOffline(User $actor, SprintSale $sale, SprintPayment $payment): SprintPayment
    {
        try {
            return DB::transaction(function () use ($actor, $sale, $payment) {
                $receivable = Receivable::query()->findOrFail($sale->receivable_id);
                $txn = $this->finance->receive($actor, $receivable, [
                    'amount_cents' => $payment->amount_cents, 'method' => $payment->method,
                    'card_installments' => $payment->installments, 'card_brand' => $payment->card_brand, 'authorization_code' => $payment->nsu,
                ]);

                $p = SprintPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $this->ledger->record($p, $txn, 0, $p->terminal_split ? 'cielo_terminal' : null);
                $p->forceFill(['status' => 'paid', 'transaction_id' => $txn->id, 'paid_at' => now()])->save();
                $this->ledger->refreshSale($sale->id);
                $this->audit->record('sprint.payment_confirmed', $sale, metadata: ['payment_id' => $p->id, 'method' => $p->method, 'channel' => $p->channel, 'amount_cents' => $p->amount_cents]);

                return $p;
            });
        } catch (Throwable $e) {
            $payment->forceFill(['status' => 'failed'])->save();

            throw $e;
        }
    }

    /** PIX e cartão online: cobrança no gateway com o split nativo (profissionais + comissão). */
    private function startOnline(User $actor, SprintSale $sale, SprintPayment $payment, PaymentGateway $gateway): SprintPayment
    {
        $a = $payment->allocation;
        $snapshot = ['sprint_payment_id' => $payment->id, 'asaas' => $a['asaas'] ?? [], 'cielo' => $a['cielo'] ?? []];

        try {
            $charge = $this->payments->createCharge(
                $actor, Receivable::query()->findOrFail($sale->receivable_id), $gateway,
                $payment->method === 'pix' ? 'pix' : 'credit_card', $payment->amount_cents,
                CarbonImmutable::today('America/Sao_Paulo')->addDay()->toDateString(), 'sprint-'.$payment->id, $snapshot, // PIX/link válido até amanhã
            );
        } catch (Throwable $e) {
            $payment->forceFill(['status' => 'failed'])->save();

            throw $e;
        }

        $payment->forceFill(['charge_id' => $charge->id])->save();
        $this->audit->record('sprint.payment_started', $sale, metadata: ['payment_id' => $payment->id, 'charge_id' => $charge->id, 'channel' => $payment->channel, 'amount_cents' => $payment->amount_cents]);

        return $payment;
    }

    /**
     * Tela de confirmação: atualiza os pagamentos pendentes (consulta ao gateway com intervalo mínimo —
     * o webhook continua sendo o caminho principal) e devolve a situação da venda.
     */
    public function refresh(SprintSale $sale, bool $force = false): SprintSale
    {
        $interval = $force ? 0 : max(5, (int) config('sprint.poll_sync_seconds', 15));

        foreach (SprintPayment::query()->with('charge')->where('sale_id', $sale->id)->where('status', 'pending')->whereNotNull('charge_id')->get() as $p) {
            $c = $p->charge;
            if ($c->provider_charge_id && $c->isOpen() && (! $c->last_checked_at || $c->last_checked_at->lt(now()->subSeconds($interval)))) {
                try {
                    $c = $this->payments->sync($c);
                } catch (Throwable) {
                    // gateway indisponível: segue aguardando o webhook / próxima consulta
                }
            }

            $status = match ($c->status) {
                'cancelled', 'overdue' => 'cancelled',
                'failed' => 'failed',
                default => null,
            };
            if ($status) {
                SprintPayment::query()->whereKey($p->id)->where('status', 'pending')->update(['status' => $status, 'updated_at' => now()]);
            }
        }

        return $sale->fresh();
    }

    public function cancelPayment(User $actor, SprintPayment $payment): SprintPayment
    {
        if ($payment->status !== 'pending') {
            throw new BusinessRuleViolation('Somente pagamentos aguardando podem ser cancelados.', 'not_pending', 409);
        }
        if ($payment->charge?->isOpen()) {
            $this->payments->cancelCharge($actor, $payment->charge);
        }
        $payment->forceFill(['status' => 'cancelled'])->save();

        return $payment;
    }

    // ------------------------------------------------------------------ repasse

    /** Fecha o repasse interno do profissional no período: gera a conta a pagar. */
    public function settle(User $actor, SprintProfessional $professional, string $from, string $to): Payable
    {
        return DB::transaction(function () use ($actor, $professional, $from, $to) {
            $start = CarbonImmutable::parse($from, 'America/Sao_Paulo')->startOfDay()->utc();
            $end = CarbonImmutable::parse($to, 'America/Sao_Paulo')->endOfDay()->utc();

            $splits = SprintSplit::query()->where('party', 'professional')->where('professional_id', $professional->id)
                ->where('mode', 'internal')->where('status', 'pending')->whereBetween('created_at', [$start, $end])->lockForUpdate()->get();
            $total = (int) $splits->sum('amount_cents');

            if ($splits->isEmpty() || $total <= 0) {
                throw new BusinessRuleViolation('Não há valor de repasse a pagar no período.', 'nothing_to_settle');
            }

            $payable = Payable::create([
                'category_id' => $this->repasseCategory($professional->kind), 'supplier' => mb_substr($professional->name, 0, 150),
                'description' => 'Repasse Melhor Sprint '.CarbonImmutable::parse($from)->format('d/m/Y').' a '.CarbonImmutable::parse($to)->format('d/m/Y')." ({$splits->count()} lançamentos)",
                'amount_cents' => $total, 'due_date' => now('America/Sao_Paulo')->toDateString(), 'created_by' => $actor->id,
                'notes' => $professional->pix_key ? 'PIX: '.$professional->pix_key : $professional->bank_info,
            ]);

            SprintSplit::query()->whereIn('id', $splits->pluck('id'))->update(['status' => 'settled', 'payable_id' => $payable->id, 'settled_at' => now(), 'updated_at' => now()]);
            $this->audit->record('sprint.repasse_settled', $payable, metadata: ['professional_id' => $professional->id, 'amount_cents' => $total, 'splits' => $splits->count(), 'from' => $from, 'to' => $to]);

            return $payable;
        });
    }

    // ------------------------------------------------------------------ profissionais

    public function saveProfessional(array $data, ?SprintProfessional $professional = null): SprintProfessional
    {
        if (($data['split_type'] ?? 'percent') === 'percent' && (int) ($data['split_value'] ?? 0) > 10000) {
            throw new BusinessRuleViolation('Percentual entre 0 e 100.', 'invalid_split');
        }

        $professional ??= new SprintProfessional;
        $professional->fill($data + ['document' => null]);
        $professional->document = Format::digits($professional->document);
        $professional->save();

        return $professional;
    }

    /** Cria profissionais a partir dos médicos ativos (com carteira ASAAS/subordinado Cielo já cadastrados). */
    public function importDoctors(): int
    {
        $existing = SprintProfessional::query()->whereNotNull('doctor_id')->pluck('doctor_id')->all();
        $count = 0;

        foreach (DB::table('doctors')->where('company_id', $this->context->companyId())->where('status', 'active')->whereNull('deleted_at')
            ->whereNotIn('id', $existing)->orderBy('name')->get() as $d) {
            SprintProfessional::create([
                'doctor_id' => $d->id, 'kind' => 'doctor', 'name' => $d->social_name ?: $d->name, 'document' => Format::digits($d->cpf ?? null),
                'email' => $d->email, 'phone' => $d->phone, 'asaas_wallet_id' => $d->asaas_wallet_id ?? null,
                'cielo_subordinate_id' => $d->cielo_subordinate_id ?? null, 'split_type' => 'percent', 'split_value' => 0,
            ]);
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------------ internos

    /** @return list<array{professional_id: ?string, description: string, amount_cents: int, split_type: ?string, split_value: ?int, professional_cents: int, house_cents: int}> */
    private function buildItems(array $raw): array
    {
        if ($raw === [] || count($raw) > 50) {
            throw new BusinessRuleViolation('Inclua de 1 a 50 itens na venda.', 'invalid_items');
        }

        $ids = array_values(array_unique(array_filter(array_column($raw, 'professional_id'))));
        $pros = SprintProfessional::query()->whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');
        $items = [];

        foreach ($raw as $i => $row) {
            $amount = (int) ($row['amount_cents'] ?? 0);
            $description = trim((string) ($row['description'] ?? ''));
            $proId = ($row['professional_id'] ?? null) ?: null;

            if ($amount <= 0 || $description === '') {
                throw new BusinessRuleViolation('Item '.($i + 1).': informe a descrição e o valor.', 'invalid_items');
            }
            if ($proId && ! isset($pros[$proId])) {
                throw new BusinessRuleViolation('Item '.($i + 1).': profissional inativo ou inexistente.', 'invalid_professional');
            }

            $pro = $proId ? $pros[$proId] : null;
            $type = $pro ? (($row['split_type'] ?? null) ?: $pro->split_type) : null;
            $value = $pro ? (isset($row['split_value']) && $row['split_value'] !== null ? (int) $row['split_value'] : $pro->split_value) : null;

            if ($type && ! in_array($type, ['percent', 'fixed'], true) || ($type === 'percent' && $value > 10000) || ($value !== null && $value < 0)) {
                throw new BusinessRuleViolation('Item '.($i + 1).': regra de divisão inválida.', 'invalid_split');
            }

            $share = SplitMath::share($amount, $type, $value);
            $items[] = [
                'professional_id' => $proId, 'description' => mb_substr($description, 0, 200), 'amount_cents' => $amount,
                'split_type' => $type, 'split_value' => $value, 'professional_cents' => $share, 'house_cents' => $amount - $share,
            ];
        }

        return $items;
    }

    private function newSale(array $attributes): SprintSale
    {
        for ($attempt = 1; ; $attempt++) {
            $number = (int) SprintSale::query()->max('number') + 1;

            try {
                return DB::transaction(fn () => SprintSale::create($attributes + ['number' => $number]));
            } catch (QueryException $e) {
                if ($attempt >= 5) {
                    throw $e; // numeração disputada por vendas simultâneas: tenta o próximo número
                }
            }
        }
    }

    private function gatewayFor(string $channel, ?string $gatewayId): PaymentGateway
    {
        $settings = SprintSetting::current();
        $provider = $channel; // asaas, cielo_api, mock
        $preferred = $gatewayId ?? ($channel === 'cielo_api' ? $settings->card_gateway_id : $settings->pix_gateway_id);

        $gateway = PaymentGateway::query()->where('is_active', true)->where('provider', $provider)
            ->when($preferred, fn ($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferred]))
            ->orderByDesc('is_default')->orderBy('created_at')->first();

        return $gateway ?? throw new BusinessRuleViolation('Nenhum gateway '.(PaymentGateway::PROVIDERS[$provider] ?? $provider).' ativo. Configure em Administração → Pagamentos online.', 'gateway_not_configured');
    }

    private function repasseCategory(string $kind): string
    {
        $this->finance->ensureCategories();
        $name = $kind === 'doctor' ? 'Repasse médico' : 'Repasse a parceiros';

        return FinancialCategory::query()->where('type', 'expense')->where('name', $name)->value('id')
            ?? FinancialCategory::create(['type' => 'expense', 'name' => $name, 'is_active' => true])->id;
    }

    private function defaultBranch(): ?string
    {
        return $this->context->branchId() ?? Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->value('id');
    }
}
