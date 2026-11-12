<?php

namespace App\Services\Billing;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRule;
use App\Core\Support\Money;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida da assinatura:
 *   teste grátis (trialing) → assinatura no Asaas → pagamento confirmado (active)
 *   → vencida (past_due, acesso normal com aviso) → após a carência: suspended (só consulta; dados preservados)
 *   → pagou: active na hora. Cancelamento: no fim do período (ou imediato pelo proprietário) → canceled + exclusão agendada (LGPD).
 */
class BillingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function gateway(): BillingGateway
    {
        return config('services.asaas.mode') === 'asaas' ? app(AsaasGateway::class) : app(MockGateway::class);
    }

    public function startTrial(Account $account, Plan $plan, string $cycle = 'monthly', ?int $days = null): Subscription
    {
        $days ??= (int) config('wsf.billing.trial_days');
        $sub = Subscription::query()->create([
            'account_id' => $account->id, 'plan_id' => $plan->id, 'cycle' => $cycle, 'status' => $days > 0 ? 'trialing' : 'active',
            'price_cents' => $plan->priceFor($cycle), 'trial_ends_at' => $days > 0 ? now()->addDays($days) : null,
            'current_period_end' => $days > 0 ? null : now()->addMonth()->toDateString(), 'gateway' => $this->gateway()->name(),
        ]);
        $this->event($account, 'teste_iniciado', null, $sub->status, ['plano' => $plan->code, 'dias' => $days]);

        return $sub;
    }

    /** Cliente confirma a assinatura (gera a recorrência no Asaas). Durante o teste, a 1ª cobrança vence no fim do teste. */
    public function subscribe(Account $account, Plan $plan, string $cycle): Subscription
    {
        $sub = $account->subscription ?? $this->startTrial($account, $plan, $cycle, 0);
        $this->assertFits($account, $plan);

        return DB::transaction(function () use ($account, $plan, $cycle, $sub) {
            $sub->fill(['plan_id' => $plan->id, 'cycle' => $cycle, 'price_cents' => $plan->priceFor($cycle), 'cancel_at_period_end' => false]);
            $sub->setRelation('plan', $plan);
            $first = CarbonImmutable::today()->max($sub->trial_ends_at ? CarbonImmutable::parse($sub->trial_ends_at)->startOfDay() : CarbonImmutable::today());
            $gw = $this->gateway();
            if ($sub->gateway_subscription_id && $sub->gateway === $gw->name()) {
                $gw->updateSubscription($sub, $sub->price_cents, $cycle);
            } else {
                [$customer, $gwSub] = $gw->createSubscription($account, $sub, $first->toDateString());
                $sub->fill(['gateway' => $gw->name(), 'gateway_customer_id' => $customer, 'gateway_subscription_id' => $gwSub]);
            }
            if (in_array($sub->status, ['canceled', 'suspended'], true)) {
                $sub->status = 'past_due';
                $sub->past_due_since ??= today();
            }
            $sub->save();
            if ($gw->name() === 'mock' && ! $sub->invoices()->whereIn('status', ['pending', 'overdue'])->exists()) {
                $this->createLocalInvoice($sub, $first->toDateString());
            }
            $this->event($account, 'assinatura_confirmada', null, $sub->status, ['plano' => $plan->code, 'ciclo' => $cycle, 'valor' => $sub->price_cents]);

            return $sub;
        });
    }

    public function changePlan(Account $account, Plan $plan, string $cycle, bool $byPlatform = false): Subscription
    {
        $sub = $account->subscription ?? throw new BusinessRule('Cliente sem assinatura.');
        if (! $byPlatform) {
            $this->assertFits($account, $plan);
        }
        $from = $sub->plan->code;
        $sub->fill(['plan_id' => $plan->id, 'cycle' => $cycle, 'price_cents' => $plan->priceFor($cycle)]);
        if ($sub->gateway_subscription_id) {
            $this->gateway()->updateSubscription($sub, $sub->price_cents, $cycle);
        }
        $sub->save();
        $this->event($account, 'plano_alterado', $sub->status, $sub->status, ['de' => $from, 'para' => $plan->code, 'ciclo' => $cycle]);

        return $sub;
    }

    /** Pagamento vindo do webhook/sincronização (já confirmado na API). */
    public function applyPayment(array $p): ?Invoice
    {
        $sub = $p['subscription'] ? Subscription::query()->where('gateway_subscription_id', $p['subscription'])->first() : null;
        $invoice = Invoice::query()->where('gateway_payment_id', $p['id'])->first();
        $sub ??= $invoice?->subscription;
        if (! $sub) {
            return null;
        }

        return DB::transaction(function () use ($p, $sub, $invoice) {
            $invoice ??= new Invoice(['account_id' => $sub->account_id, 'subscription_id' => $sub->id, 'gateway_payment_id' => $p['id'],
                'description' => 'Assinatura '.$sub->plan->name.' ('.($sub->cycle === 'yearly' ? 'anual' : 'mensal').')']);
            $wasPaid = $invoice->status === 'paid';
            $invoice->fill(['amount_cents' => $p['amount_cents'], 'due_date' => $p['due_date'], 'status' => $p['status'], 'billing_type' => $p['billing_type'], 'invoice_url' => $p['invoice_url']]);
            if ($p['status'] === 'paid' && ! $invoice->paid_at) {
                $invoice->paid_at = $p['paid_at'] ? CarbonImmutable::parse($p['paid_at']) : now();
            }
            $invoice->save();

            if ($p['status'] === 'paid' && ! $wasPaid) {
                $this->markPaid($sub, $invoice);
            } elseif ($p['status'] === 'overdue' && in_array($sub->status, ['active', 'trialing'], true)) {
                $from = $sub->status;
                $sub->update(['status' => 'past_due', 'past_due_since' => $invoice->due_date]);
                $this->event($sub->account, 'pagamento_vencido', $from, 'past_due', ['fatura' => $invoice->id, 'valor' => $invoice->amount_cents]);
            }

            return $invoice;
        });
    }

    public function markInvoicePaidManually(Invoice $invoice, string $note, ?string $userId): void
    {
        if ($invoice->status === 'paid') {
            return;
        }
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'billing_type' => $invoice->billing_type ?? 'MANUAL', 'paid_note' => mb_substr($note, 0, 250)]);
        $this->markPaid($invoice->subscription, $invoice, $userId);
    }

    private function markPaid(Subscription $sub, Invoice $invoice, ?string $userId = null): void
    {
        $from = $sub->status;
        $base = CarbonImmutable::parse($invoice->due_date);
        $end = $sub->cycle === 'yearly' ? $base->addYear() : $base->addMonthNoOverflow();
        $sub->update(['status' => 'active', 'past_due_since' => null, 'trial_ends_at' => null,
            'current_period_end' => max($end->toDateString(), (string) $sub->current_period_end?->toDateString())]);
        $account = $sub->account;
        if ($account->status === 'suspended' && $account->status_reason === 'inadimplencia') {
            $account->update(['status' => 'active', 'status_reason' => null]);
        }
        $this->event($account, 'pagamento_confirmado', $from, 'active', ['fatura' => $invoice->id, 'valor' => Money::brl($invoice->amount_cents)], $userId);
        if ($sub->gateway === 'mock') {
            $this->createLocalInvoice($sub, $end->toDateString());
        }
    }

    /** Rotina diária: fim do teste, atraso → suspensão, cancelamentos agendados, sincronização com o Asaas. */
    public function run(): array
    {
        $stats = ['sincronizadas' => 0, 'suspensas' => 0, 'canceladas' => 0, 'vencidas' => 0];
        $grace = (int) config('wsf.billing.grace_days');

        foreach (Subscription::query()->with('account', 'plan')->whereIn('status', ['trialing', 'active', 'past_due'])->get() as $sub) {
            if ($sub->gateway === 'asaas' && $sub->gateway_subscription_id && config('services.asaas.mode') === 'asaas') {
                try {
                    foreach ($this->gateway()->listPayments($sub) as $p) {
                        $this->applyPayment($p);
                    }
                    $stats['sincronizadas']++;
                } catch (BusinessRule) {
                    // sem conexão: tenta na próxima rodada
                }
                $sub->refresh();
            }
            if ($sub->gateway === 'mock') {
                $n = Invoice::query()->where('subscription_id', $sub->id)->where('status', 'pending')->where('due_date', '<', today())->update(['status' => 'overdue']);
                $stats['vencidas'] += $n;
                if ($n && in_array($sub->status, ['active', 'trialing'], true)) {
                    $sub->update(['status' => 'past_due', 'past_due_since' => Invoice::query()->where('subscription_id', $sub->id)->where('status', 'overdue')->min('due_date')]);
                    $this->event($sub->account, 'pagamento_vencido', 'active', 'past_due');
                }
            }

            if ($sub->status === 'trialing' && $sub->trial_ends_at && $sub->trial_ends_at->isPast()) {
                $sub->update(['status' => 'past_due', 'past_due_since' => $sub->trial_ends_at->toDateString()]);
                $this->event($sub->account, 'teste_encerrado', 'trialing', 'past_due');
            }
            if ($sub->status === 'active' && $sub->current_period_end && $sub->current_period_end->lt(today()) && $sub->cancel_at_period_end) {
                $this->cancel($sub->account, false, null, 'fim do período');
                $stats['canceladas']++;

                continue;
            }
            if ($sub->status === 'past_due' && $sub->past_due_since && $sub->past_due_since->copy()->addDays($grace)->lt(today())) {
                $this->suspend($sub->account, 'inadimplencia', null);
                $stats['suspensas']++;
            }
        }

        return $stats;
    }

    public function suspend(Account $account, string $reason, ?string $userId): void
    {
        $sub = $account->subscription;
        $from = $sub?->status;
        $sub?->update(['status' => 'suspended']);
        $account->update(['status' => 'suspended', 'status_reason' => $reason]);
        $this->event($account, 'suspensa', $from, 'suspended', ['motivo' => $reason], $userId);
    }

    public function reactivate(Account $account, ?string $userId): void
    {
        $sub = $account->subscription;
        $from = $sub?->status;
        $hasDebt = $sub && $sub->invoices()->where('status', 'overdue')->exists();
        $sub?->update(['status' => $hasDebt ? 'past_due' : 'active', 'past_due_since' => $hasDebt ? today() : null]);
        $account->update(['status' => 'active', 'status_reason' => null, 'canceled_at' => null, 'purge_after' => null]);
        $this->event($account, 'reativada', $from, $sub?->status, [], $userId);
    }

    public function cancel(Account $account, bool $atPeriodEnd, ?string $userId, string $reason = 'solicitação'): void
    {
        $sub = $account->subscription;
        if ($atPeriodEnd && $sub && $sub->status === 'active' && $sub->current_period_end?->isFuture()) {
            $sub->update(['cancel_at_period_end' => true]);
            $this->event($account, 'cancelamento_agendado', 'active', 'active', ['ate' => $sub->current_period_end->toDateString()], $userId);

            return;
        }
        if ($sub) {
            try {
                $this->gateway()->cancelSubscription($sub);
            } catch (BusinessRule) {
                // o cancelamento local segue; o proprietário vê o histórico e cancela no Asaas se necessário
            }
            $from = $sub->status;
            $sub->update(['status' => 'canceled', 'cancel_at_period_end' => false]);
            Invoice::query()->where('subscription_id', $sub->id)->where('status', 'pending')->update(['status' => 'canceled']);
        }
        $account->update(['status' => 'canceled', 'status_reason' => $reason, 'canceled_at' => now(),
            'purge_after' => now()->addDays((int) config('wsf.lgpd.retention_days_after_cancel'))]);
        $this->event($account, 'cancelada', $from ?? null, 'canceled', ['motivo' => $reason, 'exclusao_em' => $account->purge_after->toDateString()], $userId);
    }

    public function extendTrial(Account $account, int $days, ?string $userId): void
    {
        $sub = $account->subscription;
        $base = $sub->trial_ends_at && $sub->trial_ends_at->isFuture() ? $sub->trial_ends_at : now();
        $from = $sub->status;
        $sub->update(['status' => 'trialing', 'trial_ends_at' => $base->copy()->addDays($days), 'past_due_since' => null]);
        if ($account->status === 'suspended') {
            $account->update(['status' => 'active', 'status_reason' => null]);
        }
        $this->event($account, 'teste_prorrogado', $from, 'trialing', ['dias' => $days], $userId);
    }

    /** Limites do plano (empresas/usuários) — conferidos ao criar empresa/usuário e ao trocar de plano. */
    public function assertFits(Account $account, Plan $plan): void
    {
        $companies = Company::query()->withoutGlobalScopes()->where('account_id', $account->id)->where('active', true)->count();
        $users = Membership::query()->withoutGlobalScopes()->where('account_id', $account->id)->where('status', 'active')->count();
        if ($companies > $plan->max_companies || $users > $plan->max_users) {
            throw new BusinessRule("O plano {$plan->name} permite {$plan->max_companies} empresa(s) e {$plan->max_users} usuário(s); hoje você usa {$companies} e {$users}. Desative o excedente antes de trocar.", 'limite');
        }
    }

    public function assertCanAdd(Account $account, string $what): void
    {
        $plan = $account->plan() ?? throw new BusinessRule('Cliente sem plano.');
        $count = $what === 'company'
            ? Company::query()->withoutGlobalScopes()->where('account_id', $account->id)->where('active', true)->count()
            : Membership::query()->withoutGlobalScopes()->where('account_id', $account->id)->where('status', 'active')->count();
        $max = $what === 'company' ? $plan->max_companies : $plan->max_users;
        if ($count >= $max) {
            throw new BusinessRule('Limite do plano '.$plan->name.' atingido ('.$max.' '.($what === 'company' ? 'empresa(s)' : 'usuário(s)').'). Faça upgrade em Assinatura.', 'limite');
        }
    }

    private function createLocalInvoice(Subscription $sub, string $due): Invoice
    {
        return Invoice::query()->create(['account_id' => $sub->account_id, 'subscription_id' => $sub->id, 'amount_cents' => $sub->price_cents, 'due_date' => $due,
            'status' => 'pending', 'billing_type' => 'UNDEFINED', 'gateway_payment_id' => 'mock_pay_'.bin2hex(random_bytes(6)),
            'description' => 'Assinatura '.$sub->plan->name.' ('.($sub->cycle === 'yearly' ? 'anual' : 'mensal').')']);
    }

    private function event(Account $account, string $event, ?string $from, ?string $to, array $data = [], ?string $userId = null): void
    {
        SubscriptionEvent::query()->create(['account_id' => $account->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'data' => $data ?: null, 'user_id' => $userId ?? auth()->id()]);
        $this->audit->log('assinatura.'.$event, $account, $data, accountId: $account->id, userId: $userId ?? auth()->id(), channel: app()->runningInConsole() ? 'sistema' : 'web');
    }
}
