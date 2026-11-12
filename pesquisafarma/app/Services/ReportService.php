<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Quote;
use App\Models\QuotePharmacy;
use App\Models\Receipt;
use App\Models\User;
use App\Models\WaMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Relatórios gerenciais (vendas, comissões, cotado × comprado, televendas, tempos, farmácias). */
class ReportService
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    public static function fromRequest(): self
    {
        $from = request('de') ? CarbonImmutable::parse(request('de')) : CarbonImmutable::today()->startOfMonth();
        $to = request('ate') ? CarbonImmutable::parse(request('ate')) : CarbonImmutable::today();

        return new self($from->startOfDay(), $to->endOfDay());
    }

    private function range(): array
    {
        return [$this->from, $this->to];
    }

    private function orders()
    {
        return Order::query()->where('status', '!=', 'cancelado')->whereBetween('created_at', $this->range());
    }

    /** Notas conferidas sem pedido (compras captadas só pela nota). */
    private function looseReceipts()
    {
        return Receipt::query()->where('status', 'conferida')->whereNull('order_id')->whereBetween('reviewed_at', $this->range());
    }

    /** Vendas por farmácia (empresa) com base de comissão. */
    public function byPharmacy(?int $pharmacyId = null): Collection
    {
        $orders = $this->orders()->when($pharmacyId, fn ($q) => $q->where('pharmacy_id', $pharmacyId))->get()->groupBy('pharmacy_id');
        $receipts = $this->looseReceipts()->when($pharmacyId, fn ($q) => $q->where('pharmacy_id', $pharmacyId))->get()->groupBy('pharmacy_id');
        $verified = Receipt::query()->where('status', 'conferida')->whereBetween('reviewed_at', $this->range())->get()->groupBy('pharmacy_id');
        $dispatches = QuotePharmacy::query()->whereBetween('sent_at', $this->range())->get()->groupBy('pharmacy_id');

        return Pharmacy::query()->when($pharmacyId, fn ($q) => $q->whereKey($pharmacyId))->with('neighborhood')->orderBy('trade_name')->get()
            ->map(function (Pharmacy $p) use ($orders, $receipts, $verified, $dispatches) {
                $o = $orders[$p->id] ?? collect();
                $r = $receipts[$p->id] ?? collect();
                $d = $dispatches[$p->id] ?? collect();
                $responded = $d->where('status', 'respondida');

                return [
                    'pharmacy' => $p,
                    'quotes_received' => $d->count(),
                    'quotes_answered' => $responded->count(),
                    'response_rate' => $d->count() ? round($responded->count() / $d->count() * 100, 1) : null,
                    'avg_response_min' => $this->avg($responded->map(fn ($x) => $x->responseMinutes())),
                    'orders' => $o->count(),
                    'orders_total' => round($o->sum('total'), 2),
                    'receipts' => ($verified[$p->id] ?? collect())->count(),
                    'receipts_total' => round(($verified[$p->id] ?? collect())->sum('total'), 2),
                    'loose_receipts_total' => round($r->sum('total'), 2),
                    'commission_base' => round($o->sum('commission_base') + $r->sum('total'), 2),
                    'commission' => round($o->sum('commission_amount') + $r->sum('commission_amount'), 2),
                    'percent' => $p->commission_percent,
                    'cycle' => $p->commission_cycle,
                ];
            })
            ->filter(fn ($row) => $pharmacyId || $row['quotes_received'] || $row['orders'] || $row['receipts'])
            ->values();
    }

    /** Compras por cliente. */
    public function byCustomer(?string $search = null): Collection
    {
        $orders = $this->orders()->get()->groupBy('customer_id');
        $receipts = Receipt::query()->where('status', 'conferida')->whereBetween('reviewed_at', $this->range())->get()->groupBy('customer_id');
        $quotes = Quote::query()->whereBetween('requested_at', $this->range())->get()->groupBy('customer_id');
        $ids = collect($orders->keys())->merge($receipts->keys())->merge($quotes->keys())->unique();

        return Customer::query()->whereIn('id', $ids)->with('neighborhood')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->get()->map(fn (Customer $c) => [
                'customer' => $c,
                'quotes' => ($quotes[$c->id] ?? collect())->count(),
                'orders' => ($orders[$c->id] ?? collect())->count(),
                'orders_total' => round(($orders[$c->id] ?? collect())->sum('total'), 2),
                'receipts' => ($receipts[$c->id] ?? collect())->count(),
                'receipts_total' => round(($receipts[$c->id] ?? collect())->sum('total'), 2),
                'conversion' => ($quotes[$c->id] ?? collect())->count() ? round(($orders[$c->id] ?? collect())->count() / ($quotes[$c->id])->count() * 100) : null,
            ])->sortByDesc(fn ($r) => max($r['orders_total'], $r['receipts_total']))->values();
    }

    /** Cotado × comprado (notas conferidas ligadas a pedidos). */
    public function quotedVsBought(): Collection
    {
        return Receipt::query()->where('status', 'conferida')->whereNotNull('order_id')->whereBetween('reviewed_at', $this->range())
            ->with(['customer', 'pharmacy', 'order', 'items'])->latest('reviewed_at')->get()
            ->map(fn (Receipt $r) => ['receipt' => $r] + ReceiptService::comparison($r));
    }

    /** Desempenho de cada televendas. */
    public function operators(): Collection
    {
        $quotes = Quote::query()->whereBetween('requested_at', $this->range())->whereNotNull('operator_id')->get()->groupBy('operator_id');
        $orders = $this->orders()->get()->groupBy('operator_id');
        $actions = ActivityLog::query()->whereBetween('created_at', $this->range())->whereNotNull('user_id')->get()->groupBy('user_id');
        $messages = WaMessage::query()->where('direction', 'out')->whereNotNull('user_id')->whereBetween('created_at', $this->range())->get()->groupBy('user_id');

        return User::query()->whereIn('role', ['televendas', 'admin'])->orderBy('name')->get()->map(function (User $u) use ($quotes, $orders, $actions, $messages) {
            $q = $quotes[$u->id] ?? collect();
            $o = $orders[$u->id] ?? collect();
            $rated = $q->whereNotNull('feedback_rating');

            return [
                'user' => $u,
                'attendances' => $q->count(),
                'orders' => $o->count(),
                'sales_total' => round($o->sum('total'), 2),
                'conversion' => $q->count() ? round($o->count() / $q->count() * 100, 1) : null,
                'avg_to_dispatch' => $this->avg($q->map(fn ($x) => Quote::minutesBetween($x->requested_at, $x->dispatched_at))),
                'avg_to_present' => $this->avg($q->map(fn ($x) => Quote::minutesBetween($x->requested_at, $x->presented_at))),
                'avg_to_close' => $this->avg($q->map(fn ($x) => Quote::minutesBetween($x->requested_at, $x->closed_at))),
                'feedback_avg' => $rated->count() ? round($rated->avg('feedback_rating'), 2) : null,
                'feedback_count' => $rated->count(),
                'nps' => self::nps($q)['nps'],
                'nps_count' => self::nps($q)['nps_count'],
                'actions' => ($actions[$u->id] ?? collect())->count(),
                'messages' => ($messages[$u->id] ?? collect())->count(),
            ];
        })->filter(fn ($r) => $r['attendances'] || $r['actions'])->values();
    }

    /** Tempos médios do fluxo (em minutos). */
    public function times(): array
    {
        $q = Quote::query()->whereBetween('requested_at', $this->range())->get();
        $d = QuotePharmacy::query()->whereBetween('sent_at', $this->range())->whereNotNull('responded_at')->get();
        $rated = $q->whereNotNull('feedback_rating');

        return [
            'quotes' => $q->count(),
            'closed' => $q->where('status', 'finalizada')->count(),
            'orders' => $q->whereIn('status', ['pedido', 'finalizada'])->count(),
            'lost' => $q->where('status', 'perdida')->count(),
            'request_to_dispatch' => $this->avg($q->map(fn ($x) => Quote::minutesBetween($x->requested_at, $x->dispatched_at))),
            'dispatch_to_first_response' => $this->avg($q->map(fn ($x) => Quote::minutesBetween($x->dispatched_at, $x->first_response_at))),
            'pharmacy_response' => $this->avg($d->map(fn ($x) => $x->responseMinutes())),
            'request_to_present' => $this->avg($q->map(fn ($x) => Quote::minutesBetween($x->requested_at, $x->presented_at))),
            'request_to_close' => $this->avg($q->map(fn ($x) => Quote::minutesBetween($x->requested_at, $x->closed_at))),
            'feedback_avg' => $rated->count() ? round($rated->avg('feedback_rating'), 2) : null,
            'feedback_count' => $rated->count(),
            'feedback_dist' => collect(range(1, 5))->mapWithKeys(fn ($n) => [$n => $rated->where('feedback_rating', $n)->count()])->all(),
        ] + self::nps($q) + $this->postSale();
    }

    /** NPS = % promotores (9–10) − % detratores (0–6). */
    public static function nps(Collection $quotes): array
    {
        $s = $quotes->whereNotNull('nps_score');
        $n = $s->count();
        $prom = $s->where('nps_score', '>=', 9)->count();
        $det = $s->where('nps_score', '<=', 6)->count();

        return ['nps' => $n ? (int) round(($prom - $det) / $n * 100) : null, 'nps_count' => $n, 'nps_promoters' => $prom,
            'nps_passives' => $n - $prom - $det, 'nps_detractors' => $det, 'nps_avg' => $n ? round($s->avg('nps_score'), 1) : null];
    }

    /** Como sabemos se o cliente comprou: entrega confirmada, nota recebida, compra declarada. */
    public function postSale(): array
    {
        $orders = Order::query()->whereBetween('created_at', $this->range())->where('status', '!=', 'cancelado')->withCount(['receipts as verified_receipts' => fn ($q) => $q->where('status', 'conferida')])->get();
        $quotes = Quote::query()->whereBetween('requested_at', $this->range())->get();

        return [
            'ps_orders' => $orders->count(),
            'ps_delivery_confirmed' => $orders->whereNotNull('delivery_confirmed_at')->count(),
            'ps_delivered' => $orders->where('status', 'entregue')->count(),
            'ps_issues' => $orders->whereNotNull('delivery_issue')->count(),
            'ps_issues_open' => $orders->filter->hasOpenIssue()->count(),
            'ps_with_receipt' => $orders->where('verified_receipts', '>', 0)->count(),
            'ps_receipt_rate' => $orders->count() ? round($orders->where('verified_receipts', '>', 0)->count() / $orders->count() * 100, 1) : null,
            'ps_bought_without_order' => $quotes->where('customer_reported', 'comprou')->count(),
            'ps_gave_up' => $quotes->where('customer_reported', 'desistiu')->count(),
            // Controle do cliente: compras pela cotação × diretas no balcão (só pela nota/cartão)
            'ps_direct' => $direct = Receipt::query()->where('status', 'conferida')->whereNull('order_id')->whereNull('quote_id')->whereBetween('reviewed_at', $this->range())->count(),
            'ps_quote_share' => ($orders->count() + $direct) ? round($orders->count() / ($orders->count() + $direct) * 100, 1) : null,
        ];
    }

    public function totals(): array
    {
        $orders = $this->orders()->get();
        $loose = $this->looseReceipts()->get();

        return [
            'orders' => $orders->count(),
            'orders_total' => round($orders->sum('total'), 2),
            'commission' => round($orders->sum('commission_amount') + $loose->sum('commission_amount'), 2),
            'commission_base' => round($orders->sum('commission_base') + $loose->sum('total'), 2),
            'ticket' => $orders->count() ? round($orders->avg('total'), 2) : 0,
            'new_customers' => Customer::query()->whereBetween('created_at', $this->range())->count(),
            'receipts_pending' => Receipt::query()->where('status', 'pendente')->count(),
        ];
    }

    private function avg(Collection $values): ?float
    {
        $v = $values->filter(fn ($x) => $x !== null);

        return $v->count() ? round($v->avg(), 1) : null;
    }
}
