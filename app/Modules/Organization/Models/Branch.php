<?php

namespace App\Modules\Organization\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use Auditable, BelongsToCompany, HasUlids, SoftDeletes;

    protected string $auditName = 'branch';

    protected $fillable = [
        'name', 'code', 'is_headquarters', 'document', 'cnes', 'email', 'phone', 'zip_code', 'street',
        'number', 'complement', 'district', 'city', 'state', 'timezone', 'status', 'settings',
    ];

    protected $attributes = ['settings' => '{}', 'status' => 'active', 'is_headquarters' => false];

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Restringe às filiais que o usuário corrente pode acessar. */
    public function scopeAccessible(Builder $query, ?array $allowedBranchIds): Builder
    {
        return $allowedBranchIds === null ? $query : $query->whereIn($this->qualifyColumn('id'), $allowedBranchIds);
    }

    public function fullAddress(): string
    {
        return collect([
            trim(($this->street ?? '').', '.($this->number ?? ''), ', '),
            $this->complement, $this->district,
            trim(($this->city ?? '').' - '.($this->state ?? ''), ' -'),
        ])->filter()->implode(' · ');
    }
}
