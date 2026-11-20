<?php

namespace App\Http\Controllers;

use App\Core\Validation\Document;
use App\Models\Invoice;
use App\Core\Audit\AuditLogger;
use App\Models\Plan;
use App\Models\PlanChangeRequest;
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
        $sub = $account->subscription;

        return view('settings.billing', [
            'sub' => $account->subscription,
            'plans' => Plan::query()->where('active', true)->where('kind', '!=', 'custom')->orderBy('sort')->get(),
            'companiesCount' => BillingService::countable($account->id),
            'suggested' => ($sub?->plan && BillingService::countable($account->id) >= $sub->plan->max_companies) ? $this->billing->suggestPlan(BillingService::countable($account->id) + 1) : null,
            'requests' => PlanChangeRequest::query()->with('toPlan')->latest()->limit(5)->get(),
            'invoices' => Invoice::query()->where('account_id', $account->id)->latest('due_date')->limit(24)->get(),
            'events' => SubscriptionEvent::query()->where('account_id', $account->id)->latest('id')->limit(30)->get(),
            'usage' => UsageCounter::current($account->id),
            'mock' => $this->billing->gateway()->name() === 'mock',
        ]);
    }

    public function subscribe(Request $request): RedirectResponse
    {
        $d = $request->validate(['plan' => ['required', 'exists:plans,code'], 'cycle' => ['required', 'in:monthly,yearly'], 'document' => ['nullable', 'string', 'max:20', new Document],
            'consent' => ['nullable', 'boolean']]);
        $account = $request->attributes->get('account');
        if (! empty($d['document'])) {
            $account->update(['document' => preg_replace('/\D/', '', $d['document'])]);
        }
        $plan = Plan::query()->where('code', $d['plan'])->where('active', true)->firstOrFail();
        $sub = $account->subscription;
        if ($sub && $sub->gateway_subscription_id && ! in_array($sub->status, ['canceled'], true)) {
            // 1.1.7: troca de plano só com autorização expressa do cliente (o novo valor passa a ser cobrado no Asaas)
            if (($sub->plan_id !== $plan->id || $sub->cycle !== $d['cycle']) && ! $request->boolean('consent')) {
                return back()->with('error', 'Para mudar de plano, marque "Autorizo a mudança e o novo valor". Nada foi alterado.');
            }
            $this->billing->changePlan($account, $plan, $d['cycle']);
            $msg = 'Plano alterado para '.$plan->name.'. O novo valor vale a partir da próxima cobrança.';
        } else {
            $this->billing->subscribe($account, $plan, $d['cycle']);
            $msg = 'Assinatura confirmada! A cobrança (Pix, boleto ou cartão) aparece abaixo.';
        }

        return back()->with('ok', $msg);
    }

    /** Pedido de plano personalizado (acima de 50 empresas) ou de ajuda para escolher: vai para o proprietário; nada é cobrado. */
    public function requestPlan(Request $request, AuditLogger $audit): RedirectResponse
    {
        $d = $request->validate(['companies_wanted' => ['required', 'integer', 'min:1', 'max:100000'], 'message' => ['nullable', 'string', 'max:500']]);
        $account = $request->attributes->get('account');
        if (PlanChangeRequest::query()->where('status', 'pendente')->exists()) {
            return back()->with('error', 'Já existe um pedido em análise. Aguarde o contato da equipe.');
        }
        $r = PlanChangeRequest::query()->create(['from_plan_id' => $account->subscription?->plan_id, 'to_plan_id' => null, 'companies_wanted' => (int) $d['companies_wanted'],
            'message' => strip_tags((string) ($d['message'] ?? '')) ?: null, 'status' => 'pendente', 'requested_by' => $request->user()->id]);
        $audit->log('plano.pedido', $r, ['empresas' => $r->companies_wanted]);

        return back()->with('ok', 'Pedido enviado. A equipe entra em contato com a proposta; nenhuma cobrança muda sem a sua autorização.');
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
