<?php

namespace App\Modules\Banking\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conta bancária da clínica. Credenciais do Open Finance criptografadas e nunca exibidas. */
class BankAccount extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const SYNC = ['none' => 'Importação manual (OFX/CSV)', 'pluggy' => 'Open Finance via Pluggy (agregador)'];

    protected string $auditName = 'bank_account';

    protected array $auditExclude = ['credentials'];

    protected $fillable = ['branch_id', 'name', 'bank_code', 'agency', 'account_number', 'sync_provider', 'external_account_id', 'credentials', 'is_active'];

    protected $hidden = ['credentials'];

    protected $attributes = ['sync_provider' => 'none', 'is_active' => true];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean', 'last_synced_at' => 'datetime'];
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class);
    }

    public function label(): string
    {
        return $this->name.($this->account_number ? ' · ag. '.($this->agency ?: '—').' c/c '.$this->account_number : '');
    }
}
