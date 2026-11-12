<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\PointsEntry;
use App\Models\Receipt;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\Setting;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Programa de pontos: quanto mais o cliente compra pela Pesquisa Farma (nota fiscal conferida),
 * mais pontos ganha — e troca por brindes. Regras por valor, por produto e por farmácia.
 */
class LoyaltyService
{
    public function enabled(?Customer $c = null): bool
    {
        return Setting::get('loyalty_enabled') === '1' && (! $c || $c->loyalty_enabled);
    }

    /** @return array{points:int, details:array<int,string>} */
    public function calculate(Receipt $receipt): array
    {
        $total = (float) $receipt->total;
        $rules = LoyaltyRule::query()->where('active', true)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', today()))->get();
        $details = [];
        $points = 0.0;

        $valueRules = $rules->where('type', 'por_valor')->filter(fn ($r) => $total >= $r->min_purchase);
        $perReal = $valueRules->isNotEmpty() ? (float) $valueRules->max('points_per_real') : (float) Setting::get('points_per_real');
        if ($perReal > 0) {
            $points += $total * $perReal;
            $details[] = number_format($perReal, 2, ',', '.').' ponto(s) por R$ 1,00 → '.floor($total * $perReal);
        }
        foreach ($rules->where('type', 'por_produto') as $r) {
            $kw = mb_strtolower((string) $r->keyword);
            if ($kw !== '' && $receipt->items->contains(fn ($i) => str_contains(mb_strtolower($i->description), $kw))) {
                $points += $r->bonus_points;
                $details[] = "Bônus {$r->name}: +{$r->bonus_points}";
            }
        }
        foreach ($rules->where('type', 'por_farmacia') as $r) {
            if ($r->pharmacy_id && $r->pharmacy_id === $receipt->pharmacy_id && $r->multiplier > 1) {
                $points *= $r->multiplier;
                $details[] = "{$r->name}: pontos x".rtrim(rtrim(number_format($r->multiplier, 2, ',', ''), '0'), ',');
            }
        }

        return ['points' => (int) floor($points), 'details' => $details];
    }

    public function awardForReceipt(Receipt $receipt, ?int $userId = null): int
    {
        $customer = $receipt->customer;
        if (! $this->enabled($customer) || $receipt->points_awarded > 0) {
            return 0;
        }
        $points = $this->calculate($receipt)['points'];
        if ($points <= 0) {
            return 0;
        }
        $this->add($customer, $points, 'Compra conferida '.$receipt->code, ['receipt_id' => $receipt->id, 'order_id' => $receipt->order_id], $userId);
        $receipt->update(['points_awarded' => $points]);

        return $points;
    }

    public function add(Customer $customer, int $points, string $reason, array $refs = [], ?int $userId = null): void
    {
        DB::transaction(function () use ($customer, $points, $reason, $refs, $userId) {
            PointsEntry::create(['customer_id' => $customer->id, 'points' => $points, 'reason' => $reason, 'user_id' => $userId] + $refs);
            $customer->newQuery()->whereKey($customer->id)->increment('points_balance', $points);
        });
        $customer->refresh();
    }

    public function redeem(Customer $customer, Reward $reward, ?int $userId = null): Redemption
    {
        return DB::transaction(function () use ($customer, $reward, $userId) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $reward = Reward::query()->lockForUpdate()->findOrFail($reward->id);
            if (! $reward->active || ($reward->stock !== null && $reward->stock <= 0)) {
                throw new InvalidArgumentException('Brinde indisponível.');
            }
            if ($customer->points_balance < $reward->points_cost) {
                throw new InvalidArgumentException('Pontos insuficientes.');
            }
            $purchases = $customer->receipts()->where('status', 'conferida')->count();
            if ($purchases < $reward->min_purchases) {
                throw new InvalidArgumentException("Este brinde exige {$reward->min_purchases} compra(s) conferida(s).");
            }
            $r = Redemption::create(['customer_id' => $customer->id, 'reward_id' => $reward->id, 'points' => $reward->points_cost]);
            PointsEntry::create(['customer_id' => $customer->id, 'points' => -$reward->points_cost, 'reason' => 'Troca: '.$reward->name, 'redemption_id' => $r->id, 'user_id' => $userId]);
            $customer->decrement('points_balance', $reward->points_cost);
            if ($reward->stock !== null) {
                $reward->decrement('stock');
            }
            Activity::log('fidelidade.troca', "{$customer->displayName()} trocou {$reward->points_cost} pts por {$reward->name}", $r, userId: $userId);

            return $r;
        });
    }

    public function cancelRedemption(Redemption $r, ?int $userId = null): void
    {
        if ($r->status === 'cancelado') {
            return;
        }
        DB::transaction(function () use ($r, $userId) {
            $r->update(['status' => 'cancelado']);
            $this->add($r->customer, $r->points, 'Estorno da troca #'.$r->id, ['redemption_id' => $r->id], $userId);
            if ($r->reward->stock !== null) {
                $r->reward->increment('stock');
            }
        });
    }
}
