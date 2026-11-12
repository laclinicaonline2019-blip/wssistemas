<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteOffer extends Model
{
    protected $guarded = [];

    protected $casts = ['unit_price' => 'float', 'available' => 'boolean'];

    public const TYPES = ['referencia' => 'Ético/Referência', 'generico' => 'Genérico', 'similar' => 'Similar'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class, 'quote_item_id');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(QuotePharmacy::class, 'quote_pharmacy_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
