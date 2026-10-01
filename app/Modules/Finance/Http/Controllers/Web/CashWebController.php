<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\ParsesMoney;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Caixa do operador: abertura, sangria/suprimento, fechamento cego, conferência e impressão. */
class CashWebController extends Controller
{
    use ParsesMoney;

    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $context) {}

    /** "Meu caixa". */
    public function index(Request $request): View
    {
        $session = $this->finance->openSessionOf($request->user());

        return view('finance.cash.index', [
            'session' => $session?->load('branch:id,name'),
            'summary' => $session ? $this->finance->sessionSummary($session) : null,
            'transactions' => $session ? $session->transactions()->with(['receivable.patient:id,name,social_name', 'payable:id,supplier', 'creator:id,name', 'reversal:id,reversal_of'])->latest('occurred_at')->get() : collect(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->get(),
            'recent' => CashSession::query()->where('user_id', $request->user()->id)->where('status', '!=', 'open')->latest('opened_at')->limit(5)->get(),
            'methods' => FinancialTransaction::METHODS,
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $branchId = $request->validate(['branch_id' => ['required', 'string', 'size:26']])['branch_id'];
        $this->finance->openSession($request->user(), $branchId, $this->cents($request, 'opening', false, 0, 'fundo de troco') ?? 0);

        return redirect()->route('cash.index')->with('success', 'Caixa aberto.');
    }

    public function movement(Request $request, CashSession $session): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['withdrawal', 'deposit'])],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['reason' => 'motivo']);
        $this->finance->movement($request->user(), $session, $data['kind'], $this->cents($request, 'amount'), $data['reason']);

        return back()->with('success', $data['kind'] === 'withdrawal' ? 'Sangria registrada.' : 'Suprimento registrado.');
    }

    public function close(Request $request, CashSession $session): RedirectResponse
    {
        $request->validate(['notes' => ['nullable', 'string', 'max:500'], 'declared' => ['required', 'array']]);
        $declared = [];

        foreach ((array) $request->input('declared') as $method => $value) {
            if (! array_key_exists($method, FinancialTransaction::METHODS)) {
                continue;
            }
            $cents = $value === null || $value === '' ? 0 : Format::parseMoney($value);
            if ($cents === null || $cents < 0) {
                throw ValidationException::withMessages(["declared.{$method}" => 'Valor inválido em '.FinancialTransaction::METHODS[$method].'.']);
            }
            $declared[$method] = $cents;
        }

        $closed = $this->finance->closeSession($request->user(), $session, $declared, $request->input('notes'));

        return redirect()->route('cash.show', $closed)->with('success', 'Caixa fechado. Aguarda conferência.');
    }

    /** Lista de caixas para conferência / consulta. */
    public function sessions(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'closed', 'reviewed', 'all'])]])['status'] ?? 'closed';

        return view('finance.cash.sessions', [
            'status' => $status,
            'sessions' => CashSession::query()->with(['operator:id,name', 'branch:id,name', 'reviewer:id,name'])
                ->accessibleBranches($this->context->allowedBranchIds())
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->latest('opened_at')->paginate(30)->withQueryString(),
        ]);
    }

    public function show(Request $request, CashSession $session): View
    {
        $user = $request->user();
        abort_unless($session->user_id === $user->id || $user->hasPermission('caixa.conferir', $session->branch_id) || $user->hasPermission('financeiro.visualizar', $session->branch_id), 403);

        return view('finance.cash.show', [
            'session' => $session->load(['operator:id,name', 'branch:id,name', 'reviewer:id,name']),
            'summary' => $this->finance->sessionSummary($session),
            'transactions' => $session->transactions()->with(['receivable.patient:id,name,social_name', 'payable:id,supplier', 'creator:id,name', 'reversal:id,reversal_of'])->get(),
            'methods' => FinancialTransaction::METHODS,
            'canReview' => $user->hasPermission('caixa.conferir', $session->branch_id) && $session->user_id !== $user->id,
        ]);
    }

    public function review(Request $request, CashSession $session): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('caixa.conferir', $session->branch_id), 403);
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:500']])['notes'] ?? null;
        $this->finance->reviewSession($request->user(), $session, $notes);

        return back()->with('success', 'Caixa conferido.');
    }

    /** Relatório de fechamento (térmica ou A4). */
    public function print(Request $request, CashSession $session): View
    {
        $user = $request->user();
        abort_unless($session->user_id === $user->id || $user->hasPermission('caixa.conferir', $session->branch_id) || $user->hasPermission('financeiro.visualizar', $session->branch_id), 403);

        return view('finance.cash.print', [
            'session' => $session->load(['operator:id,name', 'branch', 'reviewer:id,name']),
            'summary' => $this->finance->sessionSummary($session),
            'transactions' => $session->transactions()->with(['receivable:id,description', 'payable:id,supplier'])->get(),
            'company' => Company::query()->findOrFail($this->context->companyId()),
            'format' => $request->query('format') === 'a4' ? 'a4' : 'thermal',
            'methods' => FinancialTransaction::METHODS,
        ]);
    }
}
