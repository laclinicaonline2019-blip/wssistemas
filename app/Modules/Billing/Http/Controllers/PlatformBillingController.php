<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Format;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Plataforma: assinaturas, MRR, faturas, pagamento manual, prorrogação de teste. */
class PlatformBillingController extends Controller
{
    public function __construct(private readonly SubscriptionService $service) {}

    public function index(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(array_keys(Subscription::STATUSES))]])['status'] ?? null;
        $subs = Subscription::query()->with(['company:id,trade_name,document,email', 'plan:id,name,price_monthly_cents,price_yearly_cents'])
            ->when($status, fn ($q, $s) => $q->where('status', $s))->orderBy('status')->get();
        $paying = Subscription::query()->with('plan')->whereIn('status', ['active', 'past_due'])->get();

        return view('platform.billing', [
            'subs' => $subs, 'status' => $status,
            'mrr' => (int) $paying->sum(fn ($s) => $s->cycle === 'yearly' ? intdiv(Subscription::priceOf($s->plan, 'yearly'), 12) : Subscription::priceOf($s->plan, 'monthly')),
            'counts' => Subscription::query()->selectRaw('status, COUNT(*) AS qty')->groupBy('status')->pluck('qty', 'status'),
            'overdue' => SubscriptionInvoice::query()->with('company:id,trade_name')->where('status', 'open')->where('due_date', '<', now('America/Sao_Paulo')->toDateString())->orderBy('due_date')->limit(50)->get(),
            'provider' => config('billing.provider'),
        ]);
    }

    public function show(Company $company): View
    {
        $s = $this->service->for($company)->load(['plan', 'pendingPlan']);

        return view('platform.billing-company', ['company' => $company, 's' => $s, 'plans' => SaasPlan::query()->orderBy('price_monthly_cents')->get(),
            'invoices' => SubscriptionInvoice::query()->where('subscription_id', $s->id)->latest()->get()]);
    }

    public function changePlan(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'string', 'size:26'], 'cycle' => ['required', Rule::in(array_keys(Subscription::CYCLES))]]);
        $this->service->changePlan($request->user(), $this->service->for($company), SaasPlan::query()->findOrFail($data['plan_id']), $data['cycle']);

        return back()->with('success', 'Plano atualizado.');
    }

    public function extendTrial(Request $request, Company $company): RedirectResponse
    {
        $days = (int) $request->validate(['days' => ['required', 'integer', 'between:1,90']])['days'];
        $s = $this->service->for($company);
        abort_unless($s->status === 'trialing', 409, 'A clínica não está em teste grátis.');
        $base = max(CarbonImmutable::parse(($s->trial_ends_on ?? now())->toDateString()), CarbonImmutable::now('America/Sao_Paulo')->startOfDay());
        $s->forceFill(['trial_ends_on' => $base->addDays($days)->toDateString()])->save();
        $this->service->syncCompany($s);
        app(AuditLogger::class)->record('billing.trial_extended', $s, companyId: $company->id, metadata: ['days' => $days, 'until' => $s->trial_ends_on->toDateString()]);

        return back()->with('success', 'Teste grátis prorrogado até '.$s->trial_ends_on->format('d/m/Y').'.');
    }

    public function manualPayment(Request $request, SubscriptionInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'string', 'max:20'], 'notes' => ['required', 'string', 'min:3', 'max:500']], [], ['amount' => 'valor', 'notes' => 'comprovante/observação']);
        $cents = Format::parseMoney($data['amount']);
        if (! $cents || $cents < $invoice->amount_cents) {
            return back()->withErrors(['amount' => 'Informe o valor recebido (no mínimo o valor da fatura).']);
        }
        $this->service->markPaid($invoice, $cents, 'manual', $request->user(), $data['notes']);

        return back()->with('success', 'Pagamento manual registrado.');
    }

    public function void(Request $request, SubscriptionInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->service->void($request->user(), $invoice, $data['reason']);

        return back()->with('success', 'Fatura cancelada.');
    }
}
