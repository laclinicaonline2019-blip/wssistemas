<?php

namespace App\Http\Controllers;

use App\Core\Validation\Document;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\SubscriptionEvent;
use App\Models\UsageCounter;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function show(Request $request): View
    {
        $account = $request->attributes->get('account')->load('subscription.plan');

        return view('settings.billing', [
            'sub' => $account->subscription,
            'plans' => Plan::query()->where('active', true)->orderBy('sort')->get(),
            'invoices' => Invoice::query()->where('account_id', $account->id)->latest('due_date')->limit(24)->get(),
            'events' => SubscriptionEvent::query()->where('account_id', $account->id)->latest('id')->limit(30)->get(),
            'usage' => UsageCounter::current($account->id),
            'mock' => $this->billing->gateway()->name() === 'mock',
        ]);
    }

    public function subscribe(Request $request): RedirectResponse
    {
        $d = $request->validate(['plan' => ['required', 'exists:plans,code'], 'cycle' => ['required', 'in:monthly,yearly'], 'document' => ['nullable', 'string', 'max:20', new Document]]);
        $account = $request->attributes->get('account');
        if (! empty($d['document'])) {
            $account->update(['document' => preg_replace('/\D/', '', $d['document'])]);
        }
        $plan = Plan::query()->where('code', $d['plan'])->where('active', true)->firstOrFail();
        $sub = $account->subscription;
        if ($sub && $sub->gateway_subscription_id && ! in_array($sub->status, ['canceled'], true)) {
            $this->billing->changePlan($account, $plan, $d['cycle']);
            $msg = 'Plano alterado para '.$plan->name.'. O novo valor vale a partir da próxima cobrança.';
        } else {
            $this->billing->subscribe($account, $plan, $d['cycle']);
            $msg = 'Assinatura confirmada! A cobrança (Pix, boleto ou cartão) aparece abaixo.';
        }

        return back()->with('ok', $msg);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:CANCELAR'], 'password' => ['required', 'current_password']]);
        $this->billing->cancel($request->attributes->get('account'), true, $request->user()->id);

        return back()->with('ok', 'Cancelamento registrado. Seus dados ficam disponíveis para consulta e exportação por '.config('wsf.lgpd.retention_days_after_cancel').' dias após o encerramento.');
    }

    /** Somente homologação (gateway simulado): confirma o pagamento de uma fatura. */
    public function simulatePayment(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($this->billing->gateway()->name() === 'mock' && ! app()->isProduction(), 404);
        abort_unless($invoice->account_id === $request->attributes->get('account')->id, 404);
        $this->billing->markInvoicePaidManually($invoice, 'Pagamento simulado (homologação)', $request->user()->id);

        return back()->with('ok', 'Pagamento simulado confirmado.');
    }
}
