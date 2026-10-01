<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FinancialTransaction;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Estorno e recibo de movimentações. */
class TransactionWebController extends Controller
{
    public function __construct(private readonly FinanceService $finance, private readonly TenantContext $context) {}

    public function reverse(Request $request, FinancialTransaction $transaction): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:230']], [], ['reason' => 'motivo'])['reason'];
        $this->finance->reverse($request->user(), $transaction, $reason);

        return back()->with('success', 'Estorno registrado. O lançamento original continua no histórico.');
    }

    /** Recibo do recebimento (A4 ou térmica). */
    public function receipt(Request $request, FinancialTransaction $transaction): View
    {
        abort_unless($transaction->kind === 'receipt' && $transaction->receivable_id, 404);
        $transaction->load(['receivable.patient', 'creator:id,name', 'reversal:id,reversal_of', 'session.branch']);
        $branch = $transaction->session?->branch ?? Branch::query()->find($transaction->branch_id);
        $company = Company::query()->findOrFail($this->context->companyId());

        return view('finance.receipt', [
            't' => $transaction, 'branch' => $branch, 'company' => $company,
            'format' => in_array($request->query('format'), ['a4', 'a5', 'thermal'], true) ? $request->query('format') : 'thermal',
            'thermalWidth' => (int) $company->setting('print.thermal_width_mm', 80),
            'preview' => $request->boolean('preview'),
        ]);
    }
}
