<?php

namespace App\Models;

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
        'recurrence_group', 'recurrence_kind', 'installment_no', 'installments_total', 'source', 'is_sample', 'external_ref', 'created_by'];

    protected function casts(): array
    {
        return ['due_date' => DateOnly::class, 'paid_at' => DateOnly::class, 'paid' => 'boolean', 'is_sample' => 'boolean', 'amount_cents' => 'integer'];
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
