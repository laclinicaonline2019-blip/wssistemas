<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PayableWebController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'cancelled', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $status = $f['status'] ?? 'open';
        $today = now('America/Sao_Paulo')->toDateString();
        $this->finance->ensureCategories();

        $items = Payable::query()->with(['category:id,name', 'branch:id,name'])
            ->accessibleBranches($this->context->allowedBranchIds())
            ->when($status === 'open', fn ($q) => $q->open())
            ->when($status === 'overdue', fn ($q) => $q->open()->where('due_date', '<', $today))
            ->when(in_array($status, ['paid', 'cancelled'], true), fn ($q) => $q->where('status', $status))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('supplier', 'like', '%'.addcslashes($t, '%_\\').'%')
                ->orWhere('description', 'like', '%'.addcslashes($t, '%_\\').'%')))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d))
            ->orderBy('due_date')->paginate(30)->withQueryString();

        return view('finance.payables.index', [
            'items' => $items, 'status' => $status,
            'categories' => FinancialCategory::query()->where('type', 'expense')->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['required', 'string', 'size:26'],
            'supplier' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:180'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'due_date' => ['required', 'date'],
            'installments' => ['nullable', 'integer', 'between:1,60'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['supplier' => 'fornecedor', 'description' => 'descrição', 'due_date' => 'vencimento', 'installments' => 'parcelas']);
        $data['amount_cents'] = $this->cents($request, 'amount');

        $created = $this->finance->createPayable($request->user(), $data);

        return redirect()->route('payables.index')->with('success', $created->count() > 1 ? "{$created->count()} parcelas lançadas." : 'Conta a pagar lançada.');
    }

    public function show(Request $request, Payable $payable): View
    {
        return view('finance.payables.show', [
            'p' => $payable->load(['category:id,name', 'branch:id,name', 'transactions.creator:id,name', 'transactions.reversal']),
            'openSession' => $this->finance->openSessionOf($request->user()),
            'methods' => FinancialTransaction::METHODS,
        ]);
    }

    public function pay(Request $request, Payable $payable): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(FinancialTransaction::METHODS))],
            'authorization_code' => ['nullable', 'string', 'max:60'],
            'paid_on' => ['nullable', 'date'],
        ], [], ['method' => 'forma de pagamento']);
        $data['amount_cents'] = $this->cents($request, 'amount');
        $this->finance->pay($request->user(), $payable, $data);

        return back()->with('success', 'Pagamento registrado.');
    }

    public function cancel(Request $request, Payable $payable): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo'])['reason'];
        $this->finance->cancelPayable($request->user(), $payable, $reason);

        return back()->with('success', 'Conta cancelada.');
    }
}
