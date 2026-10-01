<?php

namespace App\Modules\Finance\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Caixa de um operador numa unidade (abertura → movimentações → fechamento cego → conferência). */
class CashSession extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUSES = ['open' => 'Aberto', 'closed' => 'Fechado (aguardando conferência)', 'reviewed' => 'Conferido'];

    protected $fillable = ['branch_id', 'user_id', 'opened_at', 'opening_cents'];

    protected $attributes = ['status' => 'open', 'opening_cents' => 0];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'reviewed_at' => 'datetime',
            'expected' => 'array', 'declared' => 'array', 'opening_cents' => 'integer', 'difference_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Caixa não pode ser excluído.'));
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class)->orderBy('occurred_at');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Restringe às unidades que o usuário pode acessar (null = todas). */
    public function scopeAccessibleBranches(Builder $q, ?array $branchIds): Builder
    {
        return $branchIds === null ? $q : $q->where(fn ($w) => $w->whereIn($this->getTable().'.branch_id', $branchIds));
    }
}
