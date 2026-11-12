<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Faturamento e inadimplência. */
class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $q = Invoice::query()->with('account')->latest('due_date');
        if ($st = $request->query('status')) {
            $q->where('status', $st);
        }

        return view('admin.invoices', ['list' => $q->paginate(40)->withQueryString(),
            'totals' => Invoice::query()->selectRaw('status, COUNT(*) qtd, SUM(amount_cents) total')->groupBy('status')->get()->keyBy('status')]);
    }

    public function markPaid(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:200']]);
        $billing->markInvoicePaidManually($invoice, $request->input('note'), $request->user()->id);

        return back()->with('ok', 'Baixa manual registrada.');
    }
}
