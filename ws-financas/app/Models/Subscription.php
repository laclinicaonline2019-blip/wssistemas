<?php

namespace App\Models;

use App\Core\Support\DateOnly;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Assinatura do cliente — gerida pela plataforma (sem escopo de cliente; acesso pelo Account). */
class Subscription extends Model
{
    use HasUlids;

    public const STATUS = ['trialing' => 'Em teste', 'active' => 'Ativa', 'past_due' => 'Em atraso', 'suspended' => 'Suspensa', 'canceled' => 'Cancelada'];

    protected $fillable = ['account_id', 'plan_id', 'cycle', 'status', 'price_cents', 'trial_ends_at', 'current_period_end', 'past_due_since',
        'cancel_at_period_end', 'gateway', 'gateway_customer_id', 'gateway_subscription_id'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'current_period_end' => DateOnly::class, 'past_due_since' => DateOnly::class, 'cancel_at_period_end' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('due_date');
    }
}
