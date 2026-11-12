<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\Pharmacy;
use App\Models\PrizeCampaign;
use App\Models\Receipt;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\Setting;
use App\Services\LoyaltyService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Regras de pontos, brindes, trocas e premiação mensal. */
class LoyaltyController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->input('mes', now()->format('Y-m'));

        return view('staff.loyalty.index', [
            'rules' => LoyaltyRule::query()->latest('id')->get(),
            'rewards' => Reward::query()->orderBy('points_cost')->get(),
            'redemptions' => Redemption::query()->with(['customer', 'reward'])->latest('id')->limit(50)->get(),
            'campaigns' => PrizeCampaign::query()->with('winner')->latest('month')->get(),
            'pharmacies' => Pharmacy::orderBy('trade_name')->get(),
            'month' => $month,
            'participants' => $this->participants($month),
            'topPoints' => Customer::query()->where('points_balance', '>', 0)->orderByDesc('points_balance')->limit(10)->get(),
        ]);
    }

    /** Clientes com notas conferidas no mês (participantes da premiação), com total comprado. */
    private function participants(string $month, float $min = 0)
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        return Receipt::query()->where('status', 'conferida')->whereBetween('reviewed_at', [$start, $start->endOfMonth()])
            ->when(Setting::get('prize_requires_quote') === '1', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('order_id')->orWhereNotNull('quote_id')))
            ->with('customer')->get()
            ->groupBy('customer_id')->map(fn ($rs) => ['customer' => $rs->first()->customer, 'receipts' => $rs->count(), 'total' => round($rs->sum('total'), 2)])
            ->filter(fn ($r) => $r['total'] >= $min)->sortByDesc('total')->values();
    }

    public function saveRule(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'type' => 'required|in:por_valor,por_produto,por_farmacia', 'points_per_real' => 'nullable|numeric|min:0',
            'keyword' => 'nullable|string|max:80', 'bonus_points' => 'nullable|integer|min:0', 'multiplier' => 'nullable|numeric|min:1|max:10',
            'pharmacy_id' => 'nullable|exists:pharmacies,id', 'min_purchase' => 'nullable|numeric|min:0', 'valid_until' => 'nullable|date',
        ]);
        $rule = LoyaltyRule::create(array_map(fn ($v) => $v ?? null, $data) + ['points_per_real' => 0, 'bonus_points' => 0, 'multiplier' => 1, 'min_purchase' => 0]);
        Activity::log('fidelidade.regra', 'Regra de pontos criada: '.$rule->name, $rule);

        return back()->with('ok', 'Regra criada.');
    }

    public function toggleRule(LoyaltyRule $rule)
    {
        $rule->update(['active' => ! $rule->active]);

        return back();
    }

    public function saveReward(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'description' => 'nullable|string|max:500', 'points_cost' => 'required|integer|min:1',
            'stock' => 'nullable|integer|min:0', 'min_purchases' => 'nullable|integer|min:0']);
        $reward = Reward::create($data + ['min_purchases' => 0]);
        Activity::log('fidelidade.brinde', 'Brinde cadastrado: '.$reward->name, $reward);

        return back()->with('ok', 'Brinde cadastrado.');
    }

    public function toggleReward(Reward $reward)
    {
        $reward->update(['active' => ! $reward->active]);

        return back();
    }

    public function redeemFor(Request $request, Customer $customer, LoyaltyService $loyalty)
    {
        try {
            $loyalty->redeem($customer, Reward::findOrFail($request->integer('reward_id')), auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Troca registrada.');
    }

    public function redemption(Request $request, Redemption $redemption, LoyaltyService $loyalty, WhatsAppService $wa)
    {
        $status = $request->validate(['status' => 'required|in:entregue,cancelado'])['status'];
        if ($status === 'cancelado') {
            $loyalty->cancelRedemption($redemption, auth()->id());
        } else {
            $redemption->update(['status' => 'entregue', 'delivered_at' => now()]);
            $wa->toCustomer($redemption->customer, "🎁 Seu brinde *{$redemption->reward->name}* foi entregue. Aproveite! Continue comprando pela Pesquisa Farma e acumule mais pontos. 💚");
        }
        Activity::log('fidelidade.troca_'.$status, "Troca #{$redemption->id} {$status}", $redemption);

        return back()->with('ok', 'Troca atualizada.');
    }

    public function saveCampaign(Request $request)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m', 'title' => 'required|string|max:120', 'description' => 'nullable|string|max:1000',
            'rule' => 'required|in:sorteio,ranking', 'min_total' => 'nullable|numeric|min:0']);
        PrizeCampaign::create($data + ['min_total' => 0]);

        return back()->with('ok', 'Premiação cadastrada.');
    }

    /** Sorteio (chance proporcional ao nº de notas) ou ranking (maior total comprado). */
    public function draw(PrizeCampaign $campaign, WhatsAppService $wa)
    {
        abort_if($campaign->drawn_at, 422, 'Premiação já apurada.');
        $people = $this->participants($campaign->month, (float) $campaign->min_total);
        if ($people->isEmpty()) {
            return back()->with('error', 'Nenhum participante com notas conferidas neste mês.');
        }
        if ($campaign->rule === 'ranking') {
            $winner = $people->first()['customer'];
        } else {
            $tickets = $people->flatMap(fn ($p) => array_fill(0, $p['receipts'], $p['customer']))->values();
            $winner = $tickets[random_int(0, $tickets->count() - 1)];
        }
        $campaign->update(['winner_customer_id' => $winner->id, 'drawn_at' => now()]);
        Activity::log('premiacao.apurada', "Premiação {$campaign->month}: vencedor {$winner->displayName()}", $campaign, ['participants' => $people->count()]);
        $wa->toCustomer($winner, "🏆 *Parabéns!* Você foi o(a) ganhador(a) da premiação *{$campaign->title}* da Pesquisa Farma! Em breve entraremos em contato. 🎉");

        return back()->with('ok', 'Vencedor: '.$winner->displayName());
    }
}
