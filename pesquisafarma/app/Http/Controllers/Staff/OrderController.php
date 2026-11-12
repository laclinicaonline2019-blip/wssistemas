<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Services\QuoteService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()->with(['customer.neighborhood', 'pharmacy', 'operator'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->farmacia, fn ($q, $s) => $q->where('pharmacy_id', $s))
            ->when($request->de, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->ate, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')->paginate(30)->withQueryString();

        return view('staff.orders.index', ['orders' => $orders, 'pharmacies' => Pharmacy::orderBy('trade_name')->get()]);
    }

    public function show(Order $order)
    {
        return view('staff.orders.show', ['order' => $order->load(['items', 'customer.neighborhood', 'pharmacy.neighborhood', 'quote', 'receipts.items', 'operator', 'statement'])]);
    }

    public function complete(Order $order, QuoteService $quotes)
    {
        $quotes->completeOrder($order, auth()->id());

        return back()->with('ok', 'Pedido finalizado. Pedimos a avaliação do cliente pelo WhatsApp.');
    }

    public function cancel(Order $order, QuoteService $quotes)
    {
        abort_if($order->commission_statement_id, 422, 'Pedido já está em fechamento de comissão.');
        $quotes->cancelOrder($order, auth()->id());

        return back()->with('ok', 'Pedido cancelado.');
    }
}
