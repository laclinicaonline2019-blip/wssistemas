<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quote extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'recebida', 'channel' => 'whatsapp', 'scope' => 'proximas', 'fulfillment' => 'entrega'];

    protected $casts = [
        'ai_extracted' => 'boolean', 'radius_km' => 'float',
        'requested_at' => 'datetime', 'attended_at' => 'datetime', 'dispatched_at' => 'datetime',
        'response_deadline_at' => 'datetime', 'first_response_at' => 'datetime', 'presented_at' => 'datetime',
        'closed_at' => 'datetime', 'feedback_at' => 'datetime', 'nps_at' => 'datetime', 'followup_at' => 'datetime',
        'receipt_requested_at' => 'datetime',
    ];

    public const STATUSES = [
        'recebida' => 'Recebida', 'enviada' => 'Enviada às farmácias', 'respondida' => 'Com respostas',
        'apresentada' => 'Apresentada ao cliente', 'pedido' => 'Pedido realizado', 'finalizada' => 'Finalizada',
        'perdida' => 'Perdida', 'cancelada' => 'Cancelada',
    ];

    public const OPEN = ['recebida', 'enviada', 'respondida', 'apresentada', 'pedido'];

    protected static function booted(): void
    {
        static::creating(function (self $q) {
            $q->code ??= 'COT-'.now()->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $q->requested_at ??= now();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(QuotePharmacy::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class)->latestOfMany();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class);
    }

    /** Classificação de indicação: promotor (9–10), neutro (7–8) ou detrator (0–6). */
    public static function npsGroup(?int $score): ?string
    {
        return $score === null ? null : ($score >= 9 ? 'promotor' : ($score >= 7 ? 'neutro' : 'detrator'));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Minutos entre dois marcos (null se algum faltar). */
    public static function minutesBetween($a, $b): ?float
    {
        return ($a && $b) ? round($a->diffInSeconds($b) / 60, 1) : null;
    }
}
