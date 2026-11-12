<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Staff\PromotionController;
use App\Models\CommissionStatement;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\QuotePharmacy;
use App\Models\Receipt;
use App\Services\QuoteService;
use App\Services\ReceiptService;
use App\Services\ReportService;
use App\Support\Activity;
use App\Support\Text;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Painel da farmácia: responder cotações, pedidos, vendas/comissão e promoções do dia. */
class PanelController extends Controller
{
    private function pharmacyId(): int
    {
        return (int) auth()->user()->pharmacy_id ?: abort(403, 'Usuário sem farmácia vinculada.');
    }

    private function own(QuotePharmacy $dispatch): QuotePharmacy
    {
        abort_unless($dispatch->pharmacy_id === $this->pharmacyId(), 403);

        return $dispatch;
    }

    public function index()
    {
        $pid = $this->pharmacyId();
        $base = QuotePharmacy::query()->where('pharmacy_id', $pid)->with(['quote.items', 'quote.neighborhood']);

        return view('pharmacy.home', [
            'pending' => (clone $base)->whereIn('status', ['pendente', 'visualizada'])->whereHas('quote', fn ($q) => $q->whereIn('status', ['enviada', 'respondida', 'apresentada']))->latest('id')->get(),
            'recent' => (clone $base)->whereIn('status', ['respondida', 'recusada', 'expirada'])->latest('responded_at')->limit(20)->get(),
            'month' => ReportService::fromRequest()->byPharmacy($pid)->first(),
        ]);
    }

    public function quote(QuotePharmacy $dispatch)
    {
        $this->own($dispatch)->load(['quote.items', 'quote.neighborhood', 'pharmacy', 'offers', 'suggestions']);
        if (! $dispatch->viewed_at) {
            $dispatch->forceFill(['viewed_at' => now(), 'status' => $dispatch->status === 'pendente' ? 'visualizada' : $dispatch->status])->save();
        }

        return view('pharmacy.respond', ['dispatch' => $dispatch, 'action' => route('panel.respond', $dispatch), 'declineAction' => route('panel.decline', $dispatch), 'layout' => 'layouts.app']);
    }

    public function respond(Request $request, QuotePharmacy $dispatch, QuoteService $quotes)
    {
        try {
            $quotes->respond($this->own($dispatch), $request->all(), auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('panel.home')->with('ok', 'Resposta enviada para a Pesquisa Farma.');
    }

    public function decline(Request $request, QuotePharmacy $dispatch, QuoteService $quotes)
    {
        $quotes->decline($this->own($dispatch), $request->input('reason'), auth()->id());

        return redirect()->route('panel.home')->with('ok', 'Registrado.');
    }

    public function orders()
    {
        return view('pharmacy.orders', ['orders' => Order::query()->where('pharmacy_id', $this->pharmacyId())->with(['items', 'customer.neighborhood', 'quote'])->latest('id')->paginate(30)]);
    }

    public function completeOrder(Order $order, QuoteService $quotes)
    {
        abort_unless($order->pharmacy_id === $this->pharmacyId() && $order->status === 'confirmado', 403);
        $quotes->completeOrder($order, auth()->id());

        return back()->with('ok', 'Pedido marcado como entregue/retirado.');
    }

    public function sales()
    {
        $svc = ReportService::fromRequest();
        $pid = $this->pharmacyId();

        return view('pharmacy.sales', [
            'svc' => $svc,
            'row' => $svc->byPharmacy($pid)->first(),
            'orders' => Order::query()->where('pharmacy_id', $pid)->whereBetween('created_at', [$svc->from, $svc->to])->with('customer')->latest('id')->get(),
            'statements' => CommissionStatement::query()->where('pharmacy_id', $pid)->where('status', '!=', 'cancelado')->latest('period_end')->limit(24)->get(),
        ]);
    }

    /** Balcão: confere o cartão fidelidade do cliente (nº do cartão ou celular). */
    public function card(Request $request)
    {
        $customer = null;
        $q = trim((string) $request->query('cartao'));
        if ($q !== '') {
            $digits = Text::digits($q);
            $customer = (strlen($digits) <= 7 && $digits !== '') ? Customer::find((int) $digits) : Customer::where('phone', Text::phone($digits))->first();
            if ($customer && ! $customer->isRegistered()) {
                $customer = null;
            }
        }
        $pid = $this->pharmacyId();

        return view('pharmacy.card', [
            'q' => $q, 'customer' => $customer,
            'recent' => Receipt::query()->where('pharmacy_id', $pid)->where('source', 'farmacia')->with('customer')->latest('id')->limit(20)->get(),
        ]);
    }

    public function cardSale(Request $request, Customer $customer, ReceiptService $receipts)
    {
        $data = $request->validate(['total' => 'required', 'nf_number' => 'nullable|string|max:60', 'purchase_date' => 'nullable|date|before_or_equal:today',
            'file' => 'nullable|file|max:8192|mimes:jpg,jpeg,png,webp,pdf']);
        $total = Text::money($data['total']);
        if (! $total || $total <= 0) {
            return back()->with('error', 'Informe o valor da compra.');
        }
        $f = $request->file('file');
        $r = $receipts->registerCardSale(auth()->user()->pharmacy, $customer, $total, $data['nf_number'] ?? null, $data['purchase_date'] ?? null, $f?->get(), $f?->getMimeType(), auth()->id());

        return redirect()->route('panel.card')->with('ok', "Venda {$r->code} registrada para {$customer->displayName()}. O cliente foi avisado no WhatsApp.");
    }

    public function promotions()
    {
        return view('pharmacy.promotions', ['promotions' => Promotion::query()->where('pharmacy_id', $this->pharmacyId())->latest('id')->paginate(20)]);
    }

    public function storePromotion(Request $request)
    {
        $data = PromotionController::validatePromotion($request);
        $p = Promotion::create(['pharmacy_id' => $this->pharmacyId(), 'status' => 'pendente', 'created_by' => auth()->id()] + $data);
        Activity::log('promocao.enviada', 'Farmácia enviou promoção: '.$p->title, $p);

        return back()->with('ok', 'Promoção enviada para aprovação da Pesquisa Farma.');
    }
}
