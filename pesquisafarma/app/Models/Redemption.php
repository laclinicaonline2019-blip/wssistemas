<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redemption extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'solicitado'];

    protected $casts = ['delivered_at' => 'datetime'];

    public const STATUSES = ['solicitado' => 'Solicitado', 'entregue' => 'Entregue', 'cancelado' => 'Cancelado'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }
}
