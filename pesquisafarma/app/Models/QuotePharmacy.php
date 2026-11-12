<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Envio de uma cotação para uma farmácia e a resposta dela. */
class QuotePharmacy extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pendente'];

    protected $casts = [
        'within_radius' => 'boolean', 'delivers' => 'boolean', 'distance_km' => 'float', 'delivery_fee' => 'float',
        'subtotal' => 'float', 'total' => 'float',
        'sent_at' => 'datetime', 'viewed_at' => 'datetime', 'responded_at' => 'datetime',
    ];

    public const STATUSES = ['pendente' => 'Aguardando', 'visualizada' => 'Visualizada', 'respondida' => 'Respondida', 'recusada' => 'Sem estoque/recusou', 'expirada' => 'Sem resposta'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(QuoteOffer::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(QuoteSuggestion::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function responseMinutes(): ?float
    {
        return Quote::minutesBetween($this->sent_at, $this->responded_at);
    }
}
