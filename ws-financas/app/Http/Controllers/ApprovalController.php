<?php

namespace App\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Aprovação de despesas lançadas por funcionários (administrador e gestor). */
class ApprovalController extends Controller
{
    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $ctx) {}

    public function index(): View
    {
        return view('settings.approvals', ['list' => $this->finance->pendingList($this->ctx->companyIds()), 'multi' => \App\Models\Company::query()->where('active', true)->count() > 1]);
    }

    public function decide(Request $request, string $entry): RedirectResponse
    {
        $request->validate(['decisao' => ['required', 'in:aprovar,rejeitar']]);
        $approve = $request->input('decisao') === 'aprovar';
        $e = $this->finance->decide($entry, $approve);

        return back()->with('ok', ($approve ? 'Despesa aprovada: ' : 'Despesa rejeitada: ').$e->description.' — '.brl($e->amount_cents).'.');
    }
}
