<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Membership extends Model
{
    use BelongsToAccount, HasUlids;

    protected ?string $companyColumn = null;

    public const ROLES = [
        'admin' => 'Administrador',
        'gestor' => 'Gestor',
        'financeiro' => 'Financeiro',
        'consulta' => 'Consulta',
        'contador' => 'Contador',
    ];

    protected $fillable = ['account_id', 'user_id', 'role', 'all_companies', 'status'];

    protected function casts(): array
    {
        return ['all_companies' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_access')->withPivot('account_id')->withTimestamps();
    }

    /** @return list<string>|null null = todas as empresas */
    public function allowedCompanyIds(): ?array
    {
        if ($this->all_companies && $this->role !== 'contador') {
            return null;
        }

        return $this->companies()->pluck('companies.id')->all();
    }
}
