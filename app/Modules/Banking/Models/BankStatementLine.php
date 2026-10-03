<?php

namespace App\Modules\Banking\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lançamento do extrato bancário (crédito > 0, débito < 0). */
class BankStatementLine extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUSES = ['pending' => 'A conciliar', 'reconciled' => 'Conciliado', 'ignored' => 'Ignorado'];

    protected $fillable = ['bank_account_id', 'statement_id', 'dedupe_key', 'posted_on', 'amount_cents', 'description', 'reference', 'status', 'notes'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['posted_on' => 'date', 'amount_cents' => 'integer', 'resolved_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankLineMatch::class, 'line_id')->whereNull('undone_at');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
