<?php

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChargeWebController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly PaymentService $payments, private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(array_merge(array_keys(PaymentCharge::STATUSES), ['all']))]])['status'] ?? 'all';
        $allowed = $this->context->allowedBranchIds();

        return view('payments.charges', [
            'status' => $status,
            'charges' => PaymentCharge::query()->with(['receivable:id,description,patient_id', 'receivable.patient:id,name,social_name', 'gateway:id,name,provider,mode'])
                ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest()->paginate(30)->withQueryString(),
        ]);
    }

    public function store(Request $request, Receivable $receivable): RedirectResponse
    {
        $data = $request->validate([
            'gateway_id' => ['required', 'string', 'size:26'],
            'billing_type' => ['required', Rule::in(array_keys(PaymentCharge::BILLING_TYPES))],
            'due_date' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
        ], [], ['billing_type' => 'forma', 'due_date' => 'vencimento']);

        $gateway = PaymentGateway::query()->findOrFail($data['gateway_id']);
        $charge = $this->payments->createCharge($request->user(), $receivable, $gateway, $data['billing_type'],
            $this->cents($request, 'amount'), $data['due_date'], $data['idempotency_key']);

        return redirect()->route('receivables.show', $receivable)->with('success', 'Cobrança gerada. Envie o link ao paciente.')->with('new_charge', $charge->id);
    }

    public function sync(PaymentCharge $charge): RedirectResponse
    {
        $c = $this->payments->sync($charge);

        return back()->with('success', 'Situação consultada no gateway: '.PaymentCharge::STATUSES[$c->status].'.');
    }

    public function cancel(Request $request, PaymentCharge $charge): RedirectResponse
    {
        $this->payments->cancelCharge($request->user(), $charge);

        return back()->with('success', 'Cobrança cancelada.');
    }

    public function refund(Request $request, PaymentCharge $charge): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirme o estorno.']);
        $c = $this->payments->refundCharge($request->user(), $charge);

        return back()->with('success', $c->status === 'refunded' ? 'Estorno confirmado pelo gateway e lançado no financeiro.' : 'Estorno solicitado. A baixa reversa será feita quando o gateway confirmar.');
    }

    public function simulate(Request $request, PaymentCharge $charge): RedirectResponse
    {
        $outcome = $request->validate(['outcome' => ['required', Rule::in(['paid', 'overdue', 'refunded', 'paid_wrong'])]])['outcome'];
        abort_if(app()->environment('production') && ! config('aivexa.payments.allow_mock_in_production'), 403);

        $c = $outcome === 'paid_wrong'
            ? $this->payments->simulateMock($request->user(), $charge, 'paid', $charge->amount_cents - 100)
            : $this->payments->simulateMock($request->user(), $charge, $outcome);

        if ($c->status === 'review') {
            throw new BusinessRuleViolation('MOCK: '.$c->review_reason, 'review');
        }

        return back()->with('success', 'MOCK: simulação processada pelo mesmo fluxo do webhook — situação: '.PaymentCharge::STATUSES[$c->status].'.');
    }
}
