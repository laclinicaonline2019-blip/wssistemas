<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sugestão/promoção que a farmácia oferece junto da cotação para agregar à venda. */
class QuoteSuggestion extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'float', 'original_price' => 'float', 'accepted' => 'boolean'];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(QuotePharmacy::class, 'quote_pharmacy_id');
    }
}
