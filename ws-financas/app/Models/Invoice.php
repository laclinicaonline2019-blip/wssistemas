<?php

namespace App\Models;

use App\Core\Support\DateOnly;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasUlids;

    public const STATUS = ['pending' => 'Aguardando', 'paid' => 'Paga', 'overdue' => 'Vencida', 'canceled' => 'Cancelada', 'refunded' => 'Estornada'];

    protected $fillable = ['account_id', 'subscription_id', 'amount_cents', 'due_date', 'status', 'billing_type', 'gateway_payment_id', 'invoice_url', 'description', 'paid_at', 'paid_note'];

    protected function casts(): array
    {
        return ['due_date' => DateOnly::class, 'paid_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
