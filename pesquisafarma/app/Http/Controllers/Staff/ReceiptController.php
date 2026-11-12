<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Pharmacy;
use App\Models\Receipt;
use App\Services\LoyaltyService;
use App\Services\ReceiptService;
use Illuminate\Http\Request;

/** Conferência das notas fiscais enviadas pelos clientes (captação de venda). */
class ReceiptController extends Controller
{
    public function __construct(private readonly ReceiptService $receipts) {}

    public function index(Request $request)
    {
        $receipts = Receipt::query()->with(['customer', 'pharmacy', 'order'])
            ->when($request->status ?? 'pendente', fn ($q, $s) => $s === 'todas' ? $q : $q->where('status', $s))
            ->when($request->farmacia, fn ($q, $s) => $q->where('pharmacy_id', $s))
            ->latest('id')->paginate(30)->withQueryString();

        return view('staff.receipts.index', ['receipts' => $receipts, 'pharmacies' => Pharmacy::orderBy('trade_name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['customer_id' => 'required|exists:customers,id', 'file' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,pdf']);
        $f = $request->file('file');
        $r = $this->receipts->create(Customer::find($data['customer_id']), $f->get(), $f->getMimeType(), 'painel', auth()->id());

        return redirect()->route('receipts.show', $r)->with('ok', 'Nota enviada. Confira os dados.');
    }

    public function show(Receipt $receipt, LoyaltyService $loyalty)
    {
        $receipt->load(['customer', 'pharmacy', 'items.quoteItem', 'order.items', 'quote.items', 'reviewer']);
        $orders = $receipt->customer->orders()->with('pharmacy')->where('status', '!=', 'cancelado')->latest('id')->limit(10)->get();

        return view('staff.receipts.show', [
            'receipt' => $receipt,
            'pharmacies' => Pharmacy::where('active', true)->orderBy('trade_name')->get(),
            'orders' => $orders,
            'duplicates' => ReceiptService::duplicates($receipt)->load('customer'),
            'comparison' => ReceiptService::comparison($receipt),
            'pointsPreview' => $receipt->total ? $loyalty->calculate($receipt) : null,
            'fileUrl' => $receipt->file_path ? route('file', ['nota', $receipt->id]) : null,
        ]);
    }

    public function verify(Request $request, Receipt $receipt)
    {
        abort_if($receipt->status === 'conferida' && $receipt->commission_statement_id, 422, 'Nota já incluída em fechamento.');
        $data = $request->validate([
            'pharmacy_id' => 'required|exists:pharmacies,id', 'total' => 'required', 'purchase_date' => 'nullable|date', 'nf_number' => 'nullable|string|max:60',
            'order_id' => 'nullable|exists:orders,id', 'items' => 'nullable|array',
        ]);
        $data['order_id'] = $data['order_id'] ?? null;
        try {
            $this->receipts->verify($receipt, $data, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('receipts.show', $receipt)->with('ok', 'Nota conferida: venda captada, comissão e pontos lançados.');
    }

    public function reject(Request $request, Receipt $receipt)
    {
        $this->receipts->reject($receipt, $request->validate(['reason' => 'required|string|max:200'])['reason'], auth()->id());

        return back()->with('ok', 'Nota rejeitada.');
    }

    public function readAi(Receipt $receipt)
    {
        $this->receipts->applyAi($receipt);

        return back()->with('ok', 'Leitura refeita.');
    }
}
