<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fechamento de comissão da farmácia (semanal ou mensal). */
class CommissionStatement extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'aberto'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'base_total' => 'float', 'commission_amount' => 'float', 'paid_at' => 'datetime'];

    public const STATUSES = ['aberto' => 'Aberto', 'emitido' => 'Cobrança emitida', 'pago' => 'Pago', 'cancelado' => 'Cancelado'];

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
