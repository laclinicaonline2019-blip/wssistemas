<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteItem extends Model
{
    protected $guarded = [];

    public const PREFERENCES = ['qualquer' => 'O mais barato', 'referencia' => 'Só ético/referência', 'generico' => 'Só genérico', 'similar' => 'Só similar'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(QuoteOffer::class);
    }

    public function label(): string
    {
        return trim($this->quantity.'x '.$this->description.' '.($this->presentation ?? ''));
    }
}
