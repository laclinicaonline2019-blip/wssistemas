<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Venda gerada a partir da cotação (computada no relatório e na comissão da farmácia). */
class Order extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'confirmado', 'fulfillment' => 'entrega'];

    protected $casts = [
        'subtotal' => 'float', 'delivery_fee' => 'float', 'total' => 'float', 'receipt_total' => 'float',
        'commission_percent' => 'float', 'commission_base' => 'float', 'commission_amount' => 'float',
        'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'followup_at' => 'datetime',
        'delivery_confirmed_at' => 'datetime', 'delivery_issue_at' => 'datetime', 'delivery_issue_resolved_at' => 'datetime',
    ];

    public const STATUSES = ['confirmado' => 'Confirmado', 'entregue' => 'Entregue/retirado', 'cancelado' => 'Cancelado'];

    protected static function booted(): void
    {
        static::creating(fn (self $o) => $o->code ??= 'PED-'.now()->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)));
    }

    public function hasOpenIssue(): bool
    {
        return $this->delivery_issue !== null && $this->delivery_issue_resolved_at === null;
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CommissionStatement::class, 'commission_statement_id');
    }

    /**
     * A empresa recebe sobre tudo o que o cliente comprou: com nota fiscal conferida, a base
     * é o total da nota; sem nota, o total do pedido.
     */
    public function recalculateCommission(): void
    {
        $this->commission_base = $this->status === 'cancelado' ? 0 : ($this->receipt_total ?? $this->total);
        $this->commission_amount = round($this->commission_base * $this->commission_percent / 100, 2);
    }
}
