<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $subs = Subscription::query()->with('plan')->get();
        $mrr = $subs->whereIn('status', ['active', 'past_due'])->sum(fn ($s) => $s->cycle === 'yearly' ? intdiv($s->price_cents, 12) : $s->price_cents);
        $month = now()->format('Y-m');

        return view('admin.dashboard', [
            'counts' => $subs->countBy('status'),
            'mrr' => $mrr,
            'received' => (int) Invoice::query()->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('amount_cents'),
            'overdue' => (int) Invoice::query()->where('status', 'overdue')->sum('amount_cents'),
            'overdueCount' => Invoice::query()->where('status', 'overdue')->count(),
            'trialsEnding' => Subscription::query()->with('account')->where('status', 'trialing')->where('trial_ends_at', '<=', now()->addDays(3))->get(),
            'byPlan' => $subs->whereIn('status', ['trialing', 'active', 'past_due'])->groupBy(fn ($s) => $s->plan->name)->map->count(),
            'usage' => UsageCounter::query()->where('period', $month)->selectRaw('SUM(whatsapp_in) wa_in, SUM(whatsapp_out) wa_out, SUM(ai_calls) ai, SUM(ai_input_tokens) tin, SUM(ai_output_tokens) tout')->first(),
            'recent' => Account::query()->with('subscription.plan')->latest()->limit(8)->get(),
            'revenue' => Invoice::query()->where('status', 'paid')->where('paid_at', '>=', now()->subMonths(11)->startOfMonth())->get()
                ->groupBy(fn ($i) => $i->paid_at->format('Y-m'))->map(fn ($g) => $g->sum('amount_cents'))->sortKeys(),
        ]);
    }

    public function usage(): View
    {
        $period = request()->query('mes', now()->format('Y-m'));
        $rows = UsageCounter::query()->where('period', $period)->orderByDesc('whatsapp_in')->get();
        $accounts = Account::query()->with('subscription.plan')->whereIn('id', $rows->pluck('account_id'))->get()->keyBy('id');

        return view('admin.usage', ['rows' => $rows, 'accounts' => $accounts, 'period' => $period]);
    }
}
