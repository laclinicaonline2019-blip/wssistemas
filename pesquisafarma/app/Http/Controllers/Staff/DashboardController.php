<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Quote;
use App\Models\Receipt;
use App\Models\Redemption;
use App\Models\WaMessage;
use App\Services\ReportService;
use Carbon\CarbonImmutable;

class DashboardController extends Controller
{
    public function index()
    {
        $today = new ReportService(CarbonImmutable::today(), CarbonImmutable::today()->endOfDay());
        $month = new ReportService(CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today()->endOfDay());

        return view('staff.dashboard', [
            'open' => Quote::query()->whereIn('status', Quote::OPEN)->with(['customer.neighborhood', 'operator'])->withCount(['dispatches', 'dispatches as answered_count' => fn ($q) => $q->where('status', 'respondida')])
                ->orderByRaw("CASE status WHEN 'recebida' THEN 0 WHEN 'respondida' THEN 1 WHEN 'enviada' THEN 2 ELSE 3 END")->oldest('requested_at')->limit(30)->get(),
            'today' => $today->totals() + $today->times(),
            'month' => $month->totals(),
            'mine' => Quote::query()->where('operator_id', auth()->id())->whereDate('requested_at', today())->count(),
            'unread' => WaMessage::query()->where('direction', 'in')->whereNull('read_at')->distinct('customer_id')->count('customer_id'),
            'receiptsPending' => Receipt::query()->where('status', 'pendente')->count(),
            'promosPending' => Promotion::query()->where('status', 'pendente')->count(),
            'redemptionsPending' => Redemption::query()->where('status', 'solicitado')->count(),
            'operators' => $today->operators(),
            'issues' => Order::query()->whereNotNull('delivery_issue')->whereNull('delivery_issue_resolved_at')->where('status', '!=', 'cancelado')->with(['customer', 'pharmacy'])->latest('delivery_issue_at')->get(),
            'postSale' => $month->times(),
        ]);
    }
}
