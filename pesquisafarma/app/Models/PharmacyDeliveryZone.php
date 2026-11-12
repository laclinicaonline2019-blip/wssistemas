<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Taxa de entrega específica de uma farmácia para um bairro. */
class PharmacyDeliveryZone extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['fee' => 'float'];

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }
}
