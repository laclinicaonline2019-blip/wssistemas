<?php

namespace App\Modules\Billing\Models;

use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Assinatura da clínica na plataforma (dado da plataforma; consultado sempre pelo company_id). */
class Subscription extends Model
{
    use HasUlids;

    public const STATUSES = ['trialing' => 'Teste grátis', 'active' => 'Ativa', 'past_due' => 'Pagamento em atraso', 'suspended' => 'Bloqueada por falta de pagamento', 'cancelled' => 'Cancelada'];

    public const CYCLES = ['monthly' => 'Mensal', 'yearly' => 'Anual'];

    protected $fillable = ['company_id', 'saas_plan_id', 'cycle', 'status', 'trial_ends_on', 'current_period_start', 'current_period_end', 'pending_plan_id', 'pending_cycle',
        'cancel_at_period_end', 'cancelled_at', 'cancel_reason', 'suspended_at', 'gateway_customer_id'];

    protected $attributes = ['cycle' => 'monthly', 'status' => 'trialing', 'cancel_at_period_end' => false];

    protected function casts(): array
    {
        return ['trial_ends_on' => 'date', 'current_period_start' => 'date', 'current_period_end' => 'date', 'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime', 'suspended_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'pending_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Fim do que já está pago/cedido (teste ou período) — a próxima fatura começa aqui. */
    public function paidUntil(): ?CarbonInterface
    {
        return $this->current_period_end ?? $this->trial_ends_on;
    }

    public static function priceOf(?SaasPlan $plan, string $cycle): int
    {
        return $plan ? (int) ($cycle === 'yearly' ? $plan->price_yearly_cents : $plan->price_monthly_cents) : 0;
    }
}
