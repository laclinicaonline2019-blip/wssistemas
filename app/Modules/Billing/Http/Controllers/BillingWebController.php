<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Gateways\MockBillingGateway;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use App\Modules\Platform\Services\PlanLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Assinatura da clínica (assinatura.gerenciar): plano, faturas, upgrade/downgrade, cancelamento. */
class BillingWebController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly SubscriptionService $service) {}

    public function index(PlanLimitService $limits): View
    {
        $company = Company::query()->findOrFail($this->context->companyId());
        $s = $this->service->for($company)->load(['plan', 'pendingPlan']);

        return view('billing.index', [
            'company' => $company, 's' => $s, 'usage' => $limits->usage($company) + ['max_doctors' => ['used' => DB::table('doctors')->where('company_id', $company->id)->whereNull('deleted_at')->where('status', 'active')->count(), 'limit' => $s->plan?->limit('max_doctors')]],
            'plans' => SaasPlan::query()->where('is_active', true)->orderBy('price_monthly_cents')->get(),
            'invoices' => SubscriptionInvoice::query()->where('subscription_id', $s->id)->latest()->limit(24)->get(),
            'locked' => $company->billingLocked(), 'mock' => config('billing.provider') !== 'asaas',
        ]);
    }

    public function changePlan(Request $request): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'string', 'size:26'], 'cycle' => ['required', Rule::in(array_keys(Subscription::CYCLES))]]);
        $s = $this->subscription();
        $plan = SaasPlan::query()->where('is_active', true)->findOrFail($data['plan_id']);
        $r = $this->service->changePlan($request->user(), $s, $plan, $data['cycle']);

        return back()->with('success', match ($r['mode']) {
            'immediate' => 'Plano alterado para '.$plan->name.'.'.($r['invoice'] ? ' Fatura proporcional emitida: '.Format::money($r['invoice']->amount_cents).'.' : ''),
            'scheduled' => 'Troca agendada: o plano '.$plan->name.' ('.mb_strtolower(Subscription::CYCLES[$data['cycle']]).') vale a partir da próxima renovação.',
            default => 'Nenhuma alteração.',
        });
    }

    public function cancel(Request $request): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);
        $s = $this->subscription();
        $this->service->cancel($request->user(), $s, $data['reason']);

        return back()->with('success', 'Cancelamento agendado para '.($s->paidUntil()?->format('d/m/Y') ?? 'o fim do período').'. Até lá tudo continua funcionando; os dados não são apagados.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $this->service->resume($request->user(), $this->subscription());

        return back()->with('success', 'Cancelamento desfeito. A assinatura continua normalmente.');
    }

    /** Abre o pagamento (cria a cobrança se ainda não existe). */
    public function pay(SubscriptionInvoice $invoice): RedirectResponse
    {
        $this->own($invoice);
        abort_unless($invoice->status === 'open', 409);
        $this->service->charge($invoice);
        $invoice->refresh();

        return $invoice->payment_url ? redirect()->away($invoice->payment_url) : back()->with('error', 'Não foi possível gerar o pagamento agora: '.($invoice->gateway_error ?? 'tente novamente em instantes.'));
    }

    /** "Já paguei": confere no gateway na hora. */
    public function check(SubscriptionInvoice $invoice): RedirectResponse
    {
        $this->own($invoice);

        return $this->service->confirm($invoice)
            ? redirect()->route('billing.index')->with('success', 'Pagamento confirmado. Obrigado!')
            : back()->with('warning', 'Pagamento ainda não confirmado pelo banco. PIX costuma levar segundos; boleto, até 3 dias úteis.');
    }

    /** MOCK (homologação): simula o pagamento — nunca em produção. */
    public function mockPay(Request $request, string $charge): RedirectResponse
    {
        abort_unless(config('billing.provider') !== 'asaas' && ! app()->environment('production'), 404);
        $invoice = SubscriptionInvoice::query()->where('provider_charge_id', $charge)->where('provider', 'mock')->firstOrFail();
        $this->own($invoice);
        MockBillingGateway::simulatePayment($charge, $invoice->amount_cents);
        $this->service->confirm($invoice);

        return redirect()->route('billing.index')->with('success', 'MOCK: pagamento simulado e confirmado (homologação — nada foi cobrado).');
    }

    private function subscription(): Subscription
    {
        return $this->service->for(Company::query()->findOrFail($this->context->companyId()));
    }

    private function own(SubscriptionInvoice $invoice): void
    {
        abort_unless($invoice->company_id === $this->context->companyId(), 404);
    }
}
