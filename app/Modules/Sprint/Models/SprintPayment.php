<?php

namespace App\Modules\Sprint\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Payments\Models\PaymentCharge;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pagamento (parcial ou total) de uma venda — pagamentos mistos são vários registros. */
class SprintPayment extends Model
{
    use BelongsToCompany, HasUlids;

    public const METHODS = ['cash' => 'Dinheiro', 'pix' => 'PIX', 'credit_card' => 'Cartão de crédito', 'debit_card' => 'Cartão de débito'];

    public const CHANNELS = [
        'cash' => 'Dinheiro no caixa', 'asaas' => 'ASAAS', 'cielo_api' => 'Cielo online (cartão digitado)',
        'cielo_terminal' => 'Maquininha Cielo', 'terminal' => 'Outra maquininha', 'mock' => 'MOCK (simulação)',
    ];

    public const STATUSES = ['pending' => 'Aguardando', 'paid' => 'Confirmado', 'cancelled' => 'Cancelado', 'failed' => 'Recusado', 'refunded' => 'Estornado'];

    protected $fillable = [
        'sale_id', 'method', 'channel', 'amount_cents', 'status', 'charge_id', 'transaction_id', 'allocation', 'card_brand',
        'installments', 'nsu', 'terminal_split', 'cash_given_cents', 'change_cents', 'idempotency_key', 'created_by', 'paid_at',
    ];

    protected $attributes = ['status' => 'pending', 'terminal_split' => false];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer', 'allocation' => 'array', 'installments' => 'integer', 'terminal_split' => 'boolean',
            'cash_given_cents' => 'integer', 'change_cents' => 'integer', 'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(SprintSale::class, 'sale_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PaymentCharge::class, 'charge_id');
    }

    public function label(): string
    {
        return (self::METHODS[$this->method] ?? $this->method).' · '.(self::CHANNELS[$this->channel] ?? $this->channel);
    }
}
