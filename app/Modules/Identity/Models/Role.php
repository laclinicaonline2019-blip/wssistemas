<?php

namespace App\Modules\Identity\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    protected string $auditName = 'role';

    protected $fillable = ['key', 'name', 'description'];

    protected $attributes = ['is_system' => false, 'is_locked' => false];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_locked' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissions->pluck('key')->sort()->values()->all();
    }
}
