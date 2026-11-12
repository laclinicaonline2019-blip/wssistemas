<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Premiação mensal para quem enviou notas fiscais conferidas no mês. */
class PrizeCampaign extends Model
{
    protected $guarded = [];

    protected $casts = ['min_total' => 'float', 'drawn_at' => 'datetime'];

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'winner_customer_id');
    }
}
