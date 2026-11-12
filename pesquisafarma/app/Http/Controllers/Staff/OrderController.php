<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Services\FollowUpService;
use App\Services\QuoteService;
use App\Support\Activity;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()->with(['customer.neighborhood', 'pharmacy', 'operator'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->pendencia, fn ($q) => $q->whereNotNull('delivery_issue')->whereNull('delivery_issue_resolved_at'))
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

    public function resolveIssue(Order $order)
    {
        $order->update(['delivery_issue_resolved_at' => now()]);
        Activity::log('posvenda.resolvido', "Pendência de entrega do pedido {$order->code} resolvida", $order);

        return back()->with('ok', 'Pendência marcada como resolvida.');
    }

    /** Pergunta agora ao cliente se o pedido chegou (sem esperar o horário automático). */
    public function askDelivery(Order $order, FollowUpService $followUp)
    {
        abort_unless($order->status === 'confirmado', 422);
        $followUp->askPostSale($order->customer, $order->quote, $order, askDelivery: true);

        return back()->with('ok', 'Pergunta enviada ao cliente pelo WhatsApp.');
    }

    public function cancel(Order $order, QuoteService $quotes)
    {
        abort_if($order->commission_statement_id, 422, 'Pedido já está em fechamento de comissão.');
        $quotes->cancelOrder($order, auth()->id());

        return back()->with('ok', 'Pedido cancelado.');
    }
}
