<?php

namespace App\Modules\Platform\Models;

use App\Core\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasPlan extends Model
{
    use Auditable, HasUlids;

    protected string $auditName = 'saas_plan';

    protected $fillable = [
        'code', 'name', 'description', 'price_monthly_cents', 'price_yearly_cents',
        'trial_days', 'limits', 'is_active',
    ];

    protected $attributes = ['limits' => '{}'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'is_active' => 'boolean',
            'price_monthly_cents' => 'integer',
            'price_yearly_cents' => 'integer',
            'trial_days' => 'integer',
        ];
    }

    public function limit(string $key, mixed $default = null): mixed
    {
        return $this->limits[$key] ?? $default;
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
