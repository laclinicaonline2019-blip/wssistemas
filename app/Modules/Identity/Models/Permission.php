<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Catálogo global de permissões (sincronizado de config/permissions.php). */
class Permission extends Model
{
    use HasUlids;

    protected $fillable = ['key', 'module', 'description', 'scope'];

    public function scopeTenant(Builder $query): Builder
    {
        return $query->where('scope', 'tenant');
    }
}
