<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['quantity' => 'float', 'unit_price' => 'float', 'total' => 'float', 'quoted_unit_price' => 'float'];

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class);
    }
}
