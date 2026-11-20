<?php

namespace App\Models;

use App\Core\Finance\EffectiveScope;
use App\Core\Support\DateOnly;
use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Lançamento: receita ou despesa de uma empresa. */
class Entry extends Model
{
    use BelongsToAccount, HasUlids, SoftDeletes;

    protected $fillable = ['company_id', 'type', 'description', 'amount_cents', 'category', 'competence', 'due_date', 'paid', 'paid_at', 'bank_account_id',
        'recurrence_group', 'recurrence_kind', 'installment_no', 'installments_total', 'source', 'is_sample', 'external_ref', 'created_by',
        'cost_center_id', 'approval_status', 'approved_by', 'approved_at', 'responsible'];

    public const PENDING = 'pendente';

    public const REJECTED = 'rejeitada';

    protected static function booted(): void
    {
        static::addGlobalScope(new EffectiveScope);
    }

    /** Inclui despesas pendentes/rejeitadas (telas e rotinas de aprovação). */
    public static function withPending(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->withoutGlobalScope(EffectiveScope::class);
    }

    public function isPending(): bool
    {
        return $this->approval_status === self::PENDING;
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    protected function casts(): array
    {
        return ['due_date' => DateOnly::class, 'paid_at' => DateOnly::class, 'paid' => 'boolean', 'is_sample' => 'boolean', 'amount_cents' => 'integer', 'approved_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** paga | vencida | aberta (mesma regra do painel original) */
    public function status(?string $today = null): string
    {
        if ($this->type === 'receita' || $this->paid) {
            return 'paga';
        }

        return $this->due_date->toDateString() < ($today ?? today()->toDateString()) ? 'vencida' : 'aberta';
    }
}
