<?php

namespace App\Modules\Billing\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Modules\Billing\Gateways\AsaasBillingGateway;
use App\Modules\Billing\Gateways\BillingGateway;
use App\Modules\Billing\Gateways\MockBillingGateway;
use App\Modules\Billing\Mail\BillingNoticeMail;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Assinatura das clínicas (Fase 16).
 *
 * Ciclo: teste grátis → fatura emitida N dias antes do fim → paga = período renovado (ativa)
 * → não paga no vencimento = "em atraso" (acesso normal + aviso) → X dias depois = "bloqueada"
 * (só o administrador entra, e só para pagar) → paga = reativada na hora.
 * Upgrade: vale na hora, cobra a diferença proporcional aos dias restantes.
 * Downgrade e troca de ciclo: valem na próxima renovação (se o uso couber no plano novo).
 * Cancelar: no fim do período já pago; os dados NUNCA são apagados.
 */
class SubscriptionService
{
    public const TZ = 'America/Sao_Paulo';

    public function __construct(private readonly AuditLogger $audit) {}

    public function gateway(): BillingGateway
    {
        return config('billing.provider') === 'asaas' ? app(AsaasBillingGateway::class) : app(MockBillingGateway::class);
    }

    public function for(Company $company): Subscription
    {
        return Subscription::query()->firstOrCreate(['company_id' => $company->id], [
            'saas_plan_id' => $company->saas_plan_id, 'status' => $company->status === Company::STATUS_TRIAL ? 'trialing' : 'active',
            'trial_ends_on' => $company->trial_ends_at?->toDateString(),
            'current_period_start' => $company->status === Company::STATUS_TRIAL ? null : $this->today()->toDateString(),
            'current_period_end' => $company->status === Company::STATUS_TRIAL ? null : $this->today()->addMonthNoOverflow()->toDateString(),
        ]);
    }

    // ------------------------------------------------------------------ faturas

    /** Fatura de renovação do próximo período (idempotente). */
    public function issueRenewal(Subscription $s): ?SubscriptionInvoice
    {
        $start = $s->paidUntil();
        $plan = $s->pending_plan_id ? SaasPlan::query()->find($s->pending_plan_id) : $s->plan;
        $cycle = $s->pending_cycle ?: $s->cycle;
        $amount = Subscription::priceOf($plan, $cycle);
        if (! $start || $s->cancel_at_period_end || $amount <= 0 || in_array($s->status, ['cancelled'], true)) {
            return null;
        }
        $start = CarbonImmutable::parse($start->toDateString(), self::TZ);
        $end = $cycle === 'yearly' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
        $key = $s->id.':'.$start->toDateString();
        if ($existing = SubscriptionInvoice::query()->where('renewal_key', $key)->first()) {
            if ($existing->status !== 'void') {
                return $existing;
            }
            $existing->forceFill(['renewal_key' => null])->save(); // cancelada (troca de plano/cancelamento desfeito): emite outra
        }

        try {
            $invoice = DB::transaction(fn () => SubscriptionInvoice::create([
                'company_id' => $s->company_id, 'subscription_id' => $s->id, 'saas_plan_id' => $plan->id, 'number' => $this->number(), 'kind' => 'renewal',
                'cycle' => $cycle, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'renewal_key' => $key,
                'description' => "Assinatura aivexaclinica — plano {$plan->name} (".mb_strtolower(Subscription::CYCLES[$cycle]).') de '.$start->format('d/m/Y').' a '.$end->subDay()->format('d/m/Y'),
                'amount_cents' => $amount, 'due_date' => max($start, $this->today())->toDateString(),
            ]));
        } catch (QueryException) {
            return SubscriptionInvoice::query()->where('renewal_key', $key)->first();
        }
        $this->charge($invoice);
        $this->notify($s, 'Nova fatura da assinatura', "Sua fatura {$invoice->number} de ".Format::money($invoice->amount_cents).' vence em '.$invoice->due_date->format('d/m/Y').'.'
            .($invoice->payment_url ? "\nPague por PIX, boleto ou cartão: {$invoice->payment_url}" : ''));
        $this->audit->record('billing.invoice_issued', $invoice, companyId: $s->company_id, actorType: 'system', metadata: ['amount_cents' => $amount, 'kind' => 'renewal']);

        return $invoice;
    }

    /** Cria a cobrança no gateway (se ainda não existe). Falha fica registrada e o cron tenta de novo. */
    public function charge(SubscriptionInvoice $invoice): void
    {
        if ($invoice->status !== 'open' || $invoice->provider_charge_id) {
            return;
        }
        $gateway = $this->gateway();
        try {
            $r = $gateway->createCharge($invoice->subscription()->with('company')->firstOrFail(), $invoice);
            $invoice->forceFill(['provider' => $gateway->name(), 'provider_charge_id' => $r['id'], 'payment_url' => $r['url'], 'gateway_error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $invoice->forceFill(['provider' => $gateway->name(), 'gateway_error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }

    /** Consulta o gateway (nunca confia só no webhook). */
    public function confirm(SubscriptionInvoice $invoice): bool
    {
        if ($invoice->status !== 'open' || ! $invoice->provider_charge_id) {
            return false;
        }
        $r = $this->gateway()->fetchCharge($invoice->provider_charge_id);
        if (! $r['paid']) {
            return false;
        }
        if ($r['amount_cents'] !== null && $r['amount_cents'] < $invoice->amount_cents) {
            $invoice->forceFill(['notes' => 'Valor pago ('.Format::money($r['amount_cents']).') menor que a fatura — conferir.'])->save();

            return false;
        }
        $this->markPaid($invoice, $r['amount_cents'] ?? $invoice->amount_cents, 'gateway');

        return true;
    }

    public function markPaid(SubscriptionInvoice $invoice, int $paidCents, string $via, ?User $actor = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($invoice, $paidCents, $via, $actor, $notes) {
            $inv = SubscriptionInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($inv->status !== 'open') {
                return;
            }
            $inv->forceFill(['status' => 'paid', 'paid_at' => now(), 'paid_cents' => $paidCents, 'paid_via' => $via, 'notes' => $notes ?? $inv->notes])->save();
            $s = Subscription::query()->whereKey($inv->subscription_id)->lockForUpdate()->firstOrFail();

            if ($inv->kind === 'renewal') {
                $s->fill(['saas_plan_id' => $inv->saas_plan_id, 'cycle' => $inv->cycle, 'current_period_start' => $inv->period_start, 'current_period_end' => $inv->period_end]);
                if ($s->pending_plan_id === $inv->saas_plan_id || $s->pending_cycle === $inv->cycle) {
                    $s->fill(['pending_plan_id' => null, 'pending_cycle' => null]);
                }
            }
            // Pagou: ativa (ou reativa) — exceto se ainda houver outra fatura vencida há mais tempo que o prazo.
            $s->fill(['status' => $this->hasBlockingDebt($s) ? $s->status : 'active', 'suspended_at' => null]);
            $s->save();
            $this->syncCompany($s);
            $this->audit->record('billing.invoice_paid', $inv, companyId: $inv->company_id, userId: $actor?->id, actorType: $actor ? 'user' : 'system',
                metadata: ['via' => $via, 'paid_cents' => $paidCents, 'status' => $s->status]);
        });
    }

    public function void(User $actor, SubscriptionInvoice $invoice, string $reason): void
    {
        if ($invoice->status !== 'open') {
            throw new BusinessRuleViolation('Só faturas em aberto podem ser canceladas.', 'invoice_not_open', 409);
        }
        if ($invoice->provider_charge_id) {
            try {
                $this->gateway()->cancelCharge($invoice->provider_charge_id);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $invoice->forceFill(['status' => 'void', 'notes' => mb_substr($reason, 0, 500)])->save();
        $this->audit->record('billing.invoice_voided', $invoice, companyId: $invoice->company_id, metadata: ['reason' => mb_substr($reason, 0, 200)]);
        $s = Subscription::query()->findOrFail($invoice->subscription_id);
        if ($s->status === 'suspended' && ! $this->hasBlockingDebt($s)) {
            $s->forceFill(['status' => 'past_due', 'suspended_at' => null])->save();
            $this->syncCompany($s);
        }
    }

    // ------------------------------------------------------------------ plano

    /** @return array{mode: string, invoice: ?SubscriptionInvoice} */
    public function changePlan(User $actor, Subscription $s, SaasPlan $plan, string $cycle): array
    {
        if (! $plan->is_active) {
            throw new BusinessRuleViolation('Plano indisponível.', 'plan_inactive');
        }
        if (in_array($s->status, ['cancelled'], true)) {
            throw new BusinessRuleViolation('Assinatura cancelada. Fale com o suporte para reativar.', 'subscription_cancelled');
        }
        if ($plan->id === $s->saas_plan_id && $cycle === $s->cycle) {
            $s->forceFill(['pending_plan_id' => null, 'pending_cycle' => null])->save();

            return ['mode' => 'unchanged', 'invoice' => null];
        }
        $this->assertFits($s->company, $plan);

        $old = Subscription::priceOf($s->plan, $s->cycle);
        $new = Subscription::priceOf($plan, $s->cycle);
        $mode = 'scheduled';
        $invoice = null;

        if ($s->status === 'trialing' || ! $s->saas_plan_id) {
            // No teste grátis (ou sem plano): troca na hora; a primeira fatura já sai no plano novo.
            $s->fill(['saas_plan_id' => $plan->id, 'cycle' => $cycle, 'pending_plan_id' => null, 'pending_cycle' => null]);
            $mode = 'immediate';
        } elseif ($cycle === $s->cycle && $new > $old) {
            // Upgrade: vale agora; cobra a diferença proporcional aos dias que faltam no período pago.
            $start = CarbonImmutable::parse($s->current_period_start->toDateString(), self::TZ);
            $end = CarbonImmutable::parse($s->current_period_end->toDateString(), self::TZ);
            $remaining = max(0, (int) $this->today()->diffInDays($end, false));
            $total = max(1, (int) $start->diffInDays($end));
            $amount = (int) round(($new - $old) * $remaining / $total);
            $s->fill(['saas_plan_id' => $plan->id, 'pending_plan_id' => null, 'pending_cycle' => null]);
            $mode = 'immediate';
            if ($amount > 0) {
                $invoice = SubscriptionInvoice::create([
                    'company_id' => $s->company_id, 'subscription_id' => $s->id, 'saas_plan_id' => $plan->id, 'number' => $this->number(), 'kind' => 'upgrade', 'cycle' => $s->cycle,
                    'description' => "Upgrade para o plano {$plan->name} — diferença proporcional de {$remaining} dia(s) até ".$end->subDay()->format('d/m/Y'),
                    'amount_cents' => $amount, 'due_date' => $this->today()->addDays((int) config('billing.upgrade_due_days', 3))->toDateString(), 'created_by' => $actor->id,
                ]);
            }
        } else {
            // Downgrade ou troca de ciclo: na próxima renovação.
            $s->fill(['pending_plan_id' => $plan->id, 'pending_cycle' => $cycle]);
            // Fatura de renovação já emitida e em aberto com o plano antigo: refaz com o novo.
            $open = SubscriptionInvoice::query()->where('subscription_id', $s->id)->where('kind', 'renewal')->where('status', 'open')->first();
            if ($open) {
                $this->void($actor, $open, 'Substituída pela troca de plano');
                $open->forceFill(['renewal_key' => null])->save();
            }
        }
        $s->save();
        $this->syncCompany($s);
        if ($invoice) {
            $this->charge($invoice);
        }
        // Teste grátis acabando (ou já acabou): a primeira fatura sai na hora, no plano escolhido.
        if ($s->status === 'trialing' && $s->trial_ends_on && CarbonImmutable::parse($s->trial_ends_on->toDateString(), self::TZ)->lte($this->today()->addDays((int) config('billing.invoice_days_before', 7)))) {
            $this->issueRenewal($s->refresh());
        }
        $this->audit->record('billing.plan_changed', $s, companyId: $s->company_id, metadata: ['plan' => $plan->code, 'cycle' => $cycle, 'mode' => $mode, 'invoice' => $invoice?->id]);

        return ['mode' => $mode, 'invoice' => $invoice];
    }

    public function cancel(User $actor, Subscription $s, string $reason): void
    {
        $s->forceFill(['cancel_at_period_end' => true, 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
        foreach (SubscriptionInvoice::query()->where('subscription_id', $s->id)->where('kind', 'renewal')->where('status', 'open')->get() as $open) {
            $this->void($actor, $open, 'Assinatura cancelada pela clínica');
        }
        $this->audit->record('billing.cancel_requested', $s, companyId: $s->company_id, metadata: ['reason' => mb_substr($reason, 0, 200), 'until' => $s->paidUntil()?->toDateString()]);
    }

    public function resume(User $actor, Subscription $s): void
    {
        if ($s->status === 'cancelled') {
            throw new BusinessRuleViolation('Assinatura já encerrada. Fale com o suporte para reativar.', 'subscription_cancelled');
        }
        $s->forceFill(['cancel_at_period_end' => false, 'cancel_reason' => null])->save();
        $this->audit->record('billing.cancel_reverted', $s, companyId: $s->company_id);
    }

    // ------------------------------------------------------------------ rotina (cron)

    /** Renovações, cobranças pendentes, conferência no gateway e régua de cobrança. */
    public function run(): array
    {
        $stats = ['issued' => 0, 'paid' => 0, 'past_due' => 0, 'suspended' => 0, 'cancelled' => 0];
        $today = $this->today();
        $horizon = $today->addDays((int) config('billing.invoice_days_before', 7));

        foreach (Subscription::query()->with(['plan', 'company'])->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])->get() as $s) {
            try {
                $until = $s->paidUntil() ? CarbonImmutable::parse($s->paidUntil()->toDateString(), self::TZ) : null;

                if ($s->cancel_at_period_end && $until && $until->lte($today)) {
                    $s->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                    $this->syncCompany($s);
                    $this->notify($s, 'Assinatura encerrada', 'Sua assinatura foi encerrada conforme solicitado. Seus dados continuam guardados; fale conosco para reativar ou exportar.');
                    $this->audit->record('billing.cancelled', $s, companyId: $s->company_id, actorType: 'system');
                    $stats['cancelled']++;

                    continue;
                }

                if ($until && $until->lte($horizon) && $this->issueRenewal($s)?->wasRecentlyCreated) {
                    $stats['issued']++;
                }

                foreach (SubscriptionInvoice::query()->where('subscription_id', $s->id)->where('status', 'open')->get() as $inv) {
                    $this->charge($inv);
                    if ($inv->provider_charge_id && $this->confirm($inv)) {
                        $stats['paid']++;
                    }
                }
                $s->refresh();

                // Régua: período/teste acabou sem pagamento → em atraso; vencida há X dias → bloqueada.
                $until = $s->paidUntil() ? CarbonImmutable::parse($s->paidUntil()->toDateString(), self::TZ) : null;
                if (in_array($s->status, ['trialing', 'active'], true) && $until && $until->lte($today) && $this->hasOpenDebt($s)) {
                    $s->forceFill(['status' => 'past_due'])->save();
                    $this->syncCompany($s);
                    $this->notify($s, 'Pagamento em atraso', 'Não identificamos o pagamento da sua assinatura. Regularize para evitar o bloqueio do acesso em '.config('billing.suspend_after_days').' dias.');
                    $stats['past_due']++;
                }
                if ($s->status === 'past_due' && $this->hasBlockingDebt($s)) {
                    $s->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();
                    $this->syncCompany($s);
                    $this->notify($s, 'Acesso bloqueado por falta de pagamento', 'O acesso da clínica foi bloqueado. O administrador pode entrar e pagar a fatura; o acesso volta na hora após a confirmação. Nenhum dado foi apagado.');
                    $this->audit->record('billing.suspended', $s, companyId: $s->company_id, actorType: 'system');
                    $stats['suspended']++;
                }
                if ($s->status === 'trialing' && $until && $until->lte($today) && ! $this->hasOpenDebt($s) && ! $s->plan) {
                    // Teste sem plano escolhido: aguarda a escolha (a empresa fica fora do ar pelo fim do teste).
                    $this->syncCompany($s);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $stats;
    }

    public function handleWebhook(Request $request): array
    {
        $gateway = $this->gateway();
        if (! $gateway->verifyWebhook($request)) {
            return [401, 'token inválido'];
        }
        $e = $gateway->parseWebhook($request);
        if (! $e['event_id']) {
            return [200, 'ignorado'];
        }
        try {
            DB::transaction(fn () => DB::table('platform_webhook_events')->insert(['id' => (string) Str::ulid(), 'provider' => $gateway->name(), 'event_id' => mb_substr($e['event_id'], 0, 120),
                'event' => $e['event'] ? mb_substr((string) $e['event'], 0, 60) : null, 'payload' => json_encode($request->only(['event', 'payment.id', 'payment.status'])), 'created_at' => now()]));
        } catch (QueryException) {
            return [200, 'duplicado'];
        }
        $invoice = $e['charge_id'] ? SubscriptionInvoice::query()->where('provider_charge_id', $e['charge_id'])->first() : null;
        $result = $invoice ? ($this->confirm($invoice) ? 'paga' : 'sem pagamento confirmado') : 'cobrança desconhecida';
        DB::table('platform_webhook_events')->where('provider', $gateway->name())->where('event_id', $e['event_id'])->update(['processed_at' => now(), 'result' => $result]);

        return [200, $result];
    }

    // ------------------------------------------------------------------ apoio

    /** Espelha a assinatura no status da empresa (que controla login e acesso). */
    public function syncCompany(Subscription $s): void
    {
        $company = Company::query()->findOrFail($s->company_id);
        $company->forceFill([
            'saas_plan_id' => $s->saas_plan_id,
            'status' => match ($s->status) {
                'trialing' => Company::STATUS_TRIAL, 'suspended' => Company::STATUS_SUSPENDED, 'cancelled' => Company::STATUS_CANCELLED, default => Company::STATUS_ACTIVE
            },
            'trial_ends_at' => $s->status === 'trialing' && $s->trial_ends_on ? CarbonImmutable::parse($s->trial_ends_on->toDateString(), self::TZ)->utc() : $company->trial_ends_at,
        ])->save();
    }

    public function hasOpenDebt(Subscription $s): bool
    {
        return SubscriptionInvoice::query()->where('subscription_id', $s->id)->where('status', 'open')->where('due_date', '<=', $this->today()->toDateString())->exists();
    }

    /** Fatura vencida há mais que o prazo de tolerância. */
    public function hasBlockingDebt(Subscription $s): bool
    {
        return SubscriptionInvoice::query()->where('subscription_id', $s->id)->where('status', 'open')
            ->where('due_date', '<', $this->today()->subDays((int) config('billing.suspend_after_days', 10))->toDateString())->exists();
    }

    private function assertFits(Company $company, SaasPlan $plan): void
    {
        $checks = [
            'max_users' => [DB::table('users')->where('company_id', $company->id)->whereNull('deleted_at')->where('status', 'active')->count(), 'usuários ativos'],
            'max_branches' => [DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->where('status', 'active')->count(), 'filiais ativas'],
            'max_doctors' => [DB::table('doctors')->where('company_id', $company->id)->whereNull('deleted_at')->where('status', 'active')->count(), 'médicos ativos'],
        ];
        foreach ($checks as $key => [$used, $label]) {
            $limit = $plan->limit($key);
            if ($limit !== null && $used > (int) $limit) {
                throw new BusinessRuleViolation("O plano {$plan->name} permite {$limit} {$label}; hoje a clínica tem {$used}. Desative o excedente antes de trocar.", 'plan_downgrade_exceeds');
            }
        }
    }

    private function notify(Subscription $s, string $subject, string $text): void
    {
        $company = $s->company ?? Company::query()->find($s->company_id);
        if ($company?->email) {
            Mail::to($company->email)->send(new BillingNoticeMail($company->trade_name, $subject, $text));
        }
    }

    private function number(): string
    {
        return 'AVX-'.$this->today()->format('Ym').'-'.strtoupper(substr((string) Str::ulid(), -8));
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }
}
