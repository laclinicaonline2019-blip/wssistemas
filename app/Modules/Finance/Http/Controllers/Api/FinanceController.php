<?php

namespace App\Modules\Finance\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Core\Validation\ExistsInTenant;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API financeira. Valores SEMPRE em centavos (campos *_cents). */
class FinanceController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $context) {}

    public function receivables(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['open', 'paid', 'cancelled'])], 'patient_id' => ['nullable', 'string', 'size:26']]);

        return response()->json(Receivable::query()->accessibleBranches($this->context->allowedBranchIds())
            ->when(($f['status'] ?? 'open') === 'open', fn ($q) => $q->open(), fn ($q) => $q->where('status', $f['status']))
            ->when($f['patient_id'] ?? null, fn ($q, $id) => $q->where('patient_id', $id))
            ->orderBy('due_date')->paginate(50)->through(fn ($r) => $this->receivable($r)));
    }

    public function storeReceivable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'string', 'size:26'], 'category_id' => ['required', 'string', 'size:26'],
            'patient_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Patient::class)],
            'description' => ['required', 'string', 'max:200'], 'due_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $data['amount_cents'] = $this->cents($request, 'amount_cents');

        return response()->json(['data' => $this->receivable($this->finance->createReceivable($request->user(), $data))], 201);
    }

    public function receive(Request $request, Receivable $receivable): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(FinancialTransaction::METHODS))],
            'card_installments' => ['nullable', 'integer', 'between:1,24'], 'card_brand' => ['nullable', 'string', 'max:30'],
            'authorization_code' => ['nullable', 'string', 'max:60'], 'paid_on' => ['nullable', 'date'],
            'terminal_split' => ['nullable', 'boolean'],
        ]);
        $data['discount_cents'] = $this->cents($request, 'discount_cents', false, 0) ?? 0;
        $data['amount_cents'] = $this->cents($request, 'amount_cents', $data['discount_cents'] === 0, 0) ?? 0;
        $user = $request->user();

        $txn = $this->finance->receive($user, $receivable, $data,
            $user->hasPermission('financeiro.editar', $receivable->branch_id) || $user->hasPermission('caixa.conferir', $receivable->branch_id));

        return response()->json(['data' => ['receivable' => $this->receivable($receivable->fresh()), 'transaction' => $txn ? $this->txn($txn) : null]], 201);
    }

    public function cancelReceivable(Request $request, Receivable $receivable): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        return response()->json(['data' => $this->receivable($this->finance->cancelReceivable($request->user(), $receivable, $reason))]);
    }

    public function payables(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'paid', 'cancelled'])]])['status'] ?? 'open';

        return response()->json(Payable::query()->accessibleBranches($this->context->allowedBranchIds())
            ->when($status === 'open', fn ($q) => $q->open(), fn ($q) => $q->where('status', $status))
            ->orderBy('due_date')->paginate(50)->through(fn ($p) => $this->payable($p)));
    }

    public function storePayable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'string', 'size:26'], 'category_id' => ['required', 'string', 'size:26'],
            'supplier' => ['required', 'string', 'max:150'], 'description' => ['required', 'string', 'max:180'],
            'document_number' => ['nullable', 'string', 'max:60'], 'due_date' => ['required', 'date'],
            'installments' => ['nullable', 'integer', 'between:1,60'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $data['amount_cents'] = $this->cents($request, 'amount_cents');

        return response()->json(['data' => $this->finance->createPayable($request->user(), $data)->map(fn ($p) => $this->payable($p))->values()], 201);
    }

    public function pay(Request $request, Payable $payable): JsonResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(array_keys(FinancialTransaction::METHODS))], 'authorization_code' => ['nullable', 'string', 'max:60'], 'paid_on' => ['nullable', 'date']]);
        $data['amount_cents'] = $this->cents($request, 'amount_cents');

        return response()->json(['data' => $this->txn($this->finance->pay($request->user(), $payable, $data))], 201);
    }

    public function cancelPayable(Request $request, Payable $payable): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        return response()->json(['data' => $this->payable($this->finance->cancelPayable($request->user(), $payable, $reason))]);
    }

    public function reverse(Request $request, FinancialTransaction $transaction): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:230']])['reason'];

        return response()->json(['data' => $this->txn($this->finance->reverse($request->user(), $transaction, $reason))], 201);
    }

    public function currentSession(Request $request): JsonResponse
    {
        $s = $this->finance->openSessionOf($request->user());

        return response()->json(['data' => $s ? $this->session($s) + ['summary' => $this->finance->sessionSummary($s)] : null]);
    }

    public function openSession(Request $request): JsonResponse
    {
        $branchId = $request->validate(['branch_id' => ['required', 'string', 'size:26']])['branch_id'];

        return response()->json(['data' => $this->session($this->finance->openSession($request->user(), $branchId, $this->cents($request, 'opening_cents', false, 0) ?? 0))], 201);
    }

    public function movement(Request $request, CashSession $session): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['withdrawal', 'deposit'])], 'reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(['data' => $this->txn($this->finance->movement($request->user(), $session, $data['kind'], $this->cents($request, 'amount_cents'), $data['reason']))], 201);
    }

    public function closeSession(Request $request, CashSession $session): JsonResponse
    {
        $data = $request->validate(['declared' => ['required', 'array'], 'declared.*' => ['integer', 'min:0'], 'notes' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->session($this->finance->closeSession($request->user(), $session, $data['declared'], $data['notes'] ?? null))]);
    }

    public function reviewSession(Request $request, CashSession $session): JsonResponse
    {
        abort_unless($request->user()->hasPermission('caixa.conferir', $session->branch_id), 403);
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:500']])['notes'] ?? null;

        return response()->json(['data' => $this->session($this->finance->reviewSession($request->user(), $session, $notes))]);
    }

    public function summary(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'string', 'size:26']]);

        return response()->json(['data' => $this->finance->cashFlow($f['from'], $f['to'], $f['branch_id'] ?? null)]);
    }

    private function receivable(Receivable $r): array
    {
        return $r->only(['id', 'branch_id', 'category_id', 'patient_id', 'appointment_id', 'doctor_id', 'description', 'origin', 'payer_type',
            'amount_cents', 'discount_cents', 'paid_cents', 'status']) + ['balance_cents' => $r->balanceCents(), 'due_date' => $r->due_date->toDateString(), 'overdue' => $r->isOverdue()];
    }

    private function payable(Payable $p): array
    {
        return $p->only(['id', 'branch_id', 'category_id', 'supplier', 'description', 'document_number', 'amount_cents', 'paid_cents', 'status', 'installment', 'installments'])
            + ['balance_cents' => $p->balanceCents(), 'due_date' => $p->due_date->toDateString()];
    }

    private function txn(FinancialTransaction $t): array
    {
        return $t->only(['id', 'branch_id', 'direction', 'kind', 'method', 'amount_cents', 'receivable_id', 'payable_id', 'cash_session_id', 'reversal_of', 'card_installments', 'authorization_code'])
            + ['occurred_at' => $t->occurred_at->toIso8601String()];
    }

    private function session(CashSession $s): array
    {
        return $s->only(['id', 'branch_id', 'user_id', 'status', 'opening_cents', 'expected', 'declared', 'difference_cents', 'closing_notes', 'review_notes'])
            + ['opened_at' => $s->opened_at->toIso8601String(), 'closed_at' => $s->closed_at?->toIso8601String()];
    }
}
