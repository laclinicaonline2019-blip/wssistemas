<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Staff\PromotionController;
use App\Models\CommissionStatement;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\QuotePharmacy;
use App\Services\QuoteService;
use App\Services\ReportService;
use App\Support\Activity;
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
