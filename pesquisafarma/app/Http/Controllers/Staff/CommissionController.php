<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\CommissionStatement;
use App\Models\Pharmacy;
use App\Services\CommissionService;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CommissionController extends Controller
{
    public function index(Request $request)
    {
        $pending = Pharmacy::query()->where('active', true)->orderBy('trade_name')->get()->map(function (Pharmacy $p) {
            [$s, $e] = CommissionService::previousPeriod($p->commission_cycle);
            [$o, $r] = app(CommissionService::class)->pending($p, CarbonImmutable::create(2000), CarbonImmutable::today());

            return ['pharmacy' => $p, 'next_start' => $s, 'next_end' => $e, 'open_amount' => round($o->sum('commission_amount') + $r->sum('commission_amount'), 2), 'open_base' => round($o->sum('commission_base') + $r->sum('total'), 2)];
        });

        return view('staff.commissions.index', [
            'statements' => CommissionStatement::query()->with('pharmacy')->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->farmacia, fn ($q, $s) => $q->where('pharmacy_id', $s))->latest('period_end')->paginate(30)->withQueryString(),
            'pending' => $pending,
        ]);
    }

    public function generate(Request $request, CommissionService $svc)
    {
        $data = $request->validate(['pharmacy_id' => 'required|exists:pharmacies,id', 'period_start' => 'required|date', 'period_end' => 'required|date']);
        try {
            $st = $svc->generate(Pharmacy::find($data['pharmacy_id']), CarbonImmutable::parse($data['period_start']), CarbonImmutable::parse($data['period_end']), auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('commissions.show', $st)->with('ok', 'Fechamento gerado.');
    }

    public function show(CommissionStatement $statement)
    {
        return view('staff.commissions.show', ['st' => $statement->load(['pharmacy', 'orders.customer', 'receipts.customer'])]);
    }

    public function status(Request $request, CommissionStatement $statement, CommissionService $svc)
    {
        $status = $request->validate(['status' => 'required|in:emitido,pago,cancelado'])['status'];
        if ($status === 'cancelado') {
            $svc->cancel($statement, auth()->id());
        } else {
            $statement->update(['status' => $status, 'paid_at' => $status === 'pago' ? now() : null]);
            Activity::log('comissao.'.$status, "Fechamento #{$statement->id} marcado como {$status}", $statement);
        }

        return back()->with('ok', 'Fechamento atualizado.');
    }
}
