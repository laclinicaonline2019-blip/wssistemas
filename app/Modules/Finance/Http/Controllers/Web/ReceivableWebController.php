<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\SplitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReceivableWebController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'cancelled', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'patient_id' => ['nullable', 'string', 'size:26'],
        ]);
        $status = $f['status'] ?? 'open';
        $today = now('America/Sao_Paulo')->toDateString();

        $items = Receivable::query()->with(['patient:id,name,social_name,record_number', 'branch:id,name'])
            ->accessibleBranches($this->context->allowedBranchIds())
            ->when($status === 'open', fn ($q) => $q->open())
            ->when($status === 'overdue', fn ($q) => $q->open()->where('due_date', '<', $today))
            ->when(in_array($status, ['paid', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->when($f['patient_id'] ?? null, fn ($q, $id) => $q->where('patient_id', $id))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('description', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereIn('patient_id', Patient::query()->search($term)->select('id'))))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d))
            ->orderBy($status === 'paid' ? 'paid_at' : 'due_date', $status === 'paid' ? 'desc' : 'asc')->paginate(30)->withQueryString();

        return view('finance.receivables.index', [
            'items' => $items, 'status' => $status,
            'openSession' => $this->finance->openSessionOf($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        $this->finance->ensureCategories();

        return view('finance.receivables.create', [
            'categories' => FinancialCategory::query()->where('type', 'income')->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->get(),
            'patient' => $request->query('patient_id') ? Patient::query()->find($request->query('patient_id')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'string', 'size:26'],
            'category_id' => ['required', 'string', 'size:26'],
            'patient_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Patient::class)],
            'description' => ['required', 'string', 'max:200'],
            'due_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['description' => 'descrição', 'due_date' => 'vencimento']);
        $data['amount_cents'] = $this->cents($request, 'amount');

        $receivable = $this->finance->createReceivable($request->user(), $data);

        return redirect()->route('receivables.show', $receivable)->with('success', 'Conta a receber criada.');
    }

    public function show(Request $request, Receivable $receivable): View
    {
        abort_unless($this->branchAllowed($receivable->branch_id), 404);

        return view('finance.receivables.show', [
            'r' => $receivable->load(['patient', 'doctor:id,name,social_name', 'branch:id,name', 'category:id,name', 'appointment:id,protocol,starts_at',
                'transactions.creator:id,name', 'transactions.reversal']),
            'openSession' => $this->finance->openSessionOf($request->user()),
            'methods' => FinancialTransaction::METHODS,
            'charges' => PaymentCharge::query()->with('gateway:id,name,provider,mode')->where('receivable_id', $receivable->id)->latest()->get(),
            'gateways' => PaymentGateway::query()->where('is_active', true)->orderByDesc('is_default')->get(),
            'terminalSplit' => app(SplitService::class)->terminalSplitAvailable($receivable),
        ]);
    }

    public function receive(Request $request, Receivable $receivable): RedirectResponse
    {
        abort_unless($this->branchAllowed($receivable->branch_id), 404);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(FinancialTransaction::METHODS))],
            'card_installments' => ['nullable', 'integer', 'between:1,24'],
            'card_brand' => ['nullable', 'string', 'max:30'],
            'authorization_code' => ['nullable', 'string', 'max:60'],
            'paid_on' => ['nullable', 'date'],
            'terminal_split' => ['nullable', 'boolean'],
        ], [], ['method' => 'forma de pagamento']);
        $data['discount_cents'] = $this->cents($request, 'discount', false, 0, 'desconto') ?? 0;
        $data['amount_cents'] = $this->cents($request, 'amount', $data['discount_cents'] === 0, 0) ?? 0;

        $user = $request->user();
        $txn = $this->finance->receive($user, $receivable, $data,
            $user->hasPermission('financeiro.editar', $receivable->branch_id) || $user->hasPermission('caixa.conferir', $receivable->branch_id));

        $redirect = redirect()->route('receivables.show', $receivable);

        return $txn ? $redirect->with('success', 'Recebimento registrado.')->with('print_receipt', $txn->id) : $redirect->with('success', 'Desconto aplicado.');
    }

    public function cancel(Request $request, Receivable $receivable): RedirectResponse
    {
        abort_unless($this->branchAllowed($receivable->branch_id), 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo'])['reason'];
        $this->finance->cancelReceivable($request->user(), $receivable, $reason);

        return back()->with('success', 'Conta cancelada.');
    }

    private function branchAllowed(string $branchId): bool
    {
        $allowed = $this->context->allowedBranchIds();

        return $allowed === null || in_array($branchId, $allowed, true);
    }
}
