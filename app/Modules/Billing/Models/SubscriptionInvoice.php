<?php

namespace App\Modules\Billing\Models;

use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\SaasPlan;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fatura da plataforma para a clínica. */
class SubscriptionInvoice extends Model
{
    use HasUlids;

    public const KINDS = ['renewal' => 'Renovação', 'upgrade' => 'Upgrade (proporcional)', 'manual' => 'Avulsa'];

    public const STATUSES = ['open' => 'Em aberto', 'paid' => 'Paga', 'void' => 'Cancelada'];

    protected $fillable = ['company_id', 'subscription_id', 'saas_plan_id', 'number', 'kind', 'cycle', 'period_start', 'period_end', 'description', 'amount_cents',
        'due_date', 'status', 'provider', 'provider_charge_id', 'payment_url', 'gateway_error', 'notes', 'created_by', 'renewal_key'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime', 'amount_cents' => 'integer', 'paid_cents' => 'integer'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_date->lt(now('America/Sao_Paulo')->startOfDay());
    }

    public function statusLabel(): string
    {
        return $this->isOverdue() ? 'Vencida' : (self::STATUSES[$this->status] ?? $this->status);
    }
}
