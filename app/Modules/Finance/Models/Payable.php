<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Payable extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = ['open' => 'Em aberto', 'partial' => 'Parcial', 'paid' => 'Pago', 'cancelled' => 'Cancelado'];

    protected string $auditName = 'payable';

    protected $fillable = [
        'branch_id', 'category_id', 'supplier', 'description', 'document_number', 'amount_cents', 'due_date',
        'installment_group', 'installment', 'installments', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => 'open', 'paid_cents' => 0, 'installment' => 1, 'installments' => 1];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_cents' => 'integer', 'due_date' => 'date', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Contas não são excluídas — cancele com motivo.'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class)->orderBy('occurred_at');
    }

    public function balanceCents(): int
    {
        return $this->status === 'cancelled' ? 0 : $this->amount_cents - $this->paid_cents;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['open', 'partial'], true) && $this->due_date->toDateString() < now('America/Sao_Paulo')->toDateString();
    }

    public function statusLabel(): string
    {
        return $this->isOverdue() ? 'Vencida' : self::STATUSES[$this->status];
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['open', 'partial']);
    }

    /** Restringe às unidades que o usuário pode acessar (null = todas). */
    public function scopeAccessibleBranches(Builder $q, ?array $branchIds): Builder
    {
        return $branchIds === null ? $q : $q->where(fn ($w) => $w->whereIn($this->getTable().'.branch_id', $branchIds)->orWhereNull($this->getTable().'.branch_id'));
    }
}
