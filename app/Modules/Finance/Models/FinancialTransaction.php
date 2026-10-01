<?php

namespace App\Modules\Finance\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Movimentação do livro financeiro — imutável (correção = estorno). */
class FinancialTransaction extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    public const METHODS = [
        'cash' => 'Dinheiro', 'pix' => 'PIX', 'debit_card' => 'Cartão de débito', 'credit_card' => 'Cartão de crédito',
        'bank_transfer' => 'Transferência / TED', 'boleto' => 'Boleto', 'check' => 'Cheque', 'other' => 'Outro',
    ];

    public const KINDS = ['receipt' => 'Recebimento', 'payment' => 'Pagamento', 'withdrawal' => 'Sangria', 'deposit' => 'Suprimento', 'reversal' => 'Estorno'];

    protected $fillable = [
        'branch_id', 'direction', 'kind', 'method', 'amount_cents', 'receivable_id', 'payable_id', 'cash_session_id', 'reversal_of',
        'card_installments', 'card_brand', 'authorization_code', 'gateway', 'gateway_reference', 'description', 'occurred_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime', 'card_installments' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Movimentação financeira é imutável — use estorno.'));
        static::deleting(fn () => throw new LogicException('Movimentação financeira é imutável — use estorno.'));
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }

    public function signedCents(): int
    {
        return $this->direction === 'in' ? $this->amount_cents : -$this->amount_cents;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    /** Restringe às unidades que o usuário pode acessar (null = todas). */
    public function scopeAccessibleBranches(Builder $q, ?array $branchIds): Builder
    {
        return $branchIds === null ? $q : $q->where(fn ($w) => $w->whereIn($this->getTable().'.branch_id', $branchIds));
    }
}
