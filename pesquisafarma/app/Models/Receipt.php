<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nota fiscal / comprovante enviado pelo cliente (captação da venda). */
class Receipt extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pendente', 'source' => 'whatsapp', 'points_awarded' => 0];

    protected $casts = [
        'purchase_date' => 'date', 'total' => 'float', 'ai_data' => 'array', 'reviewed_at' => 'datetime',
        'commission_percent' => 'float', 'commission_amount' => 'float',
    ];

    public const STATUSES = ['pendente' => 'Aguardando conferência', 'conferida' => 'Conferida', 'rejeitada' => 'Rejeitada'];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->code ??= 'NF-'.now()->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
