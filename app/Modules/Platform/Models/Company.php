<?php

namespace App\Modules\Platform\Models;

use App\Core\Audit\Auditable;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Empresa cliente da plataforma (tenant). Não possui escopo global:
 * acesso de clínica usa sempre TenantContext::companyId() explicitamente.
 */
class Company extends Model
{
    use Auditable, HasUlids, SoftDeletes;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    protected string $auditName = 'company';

    protected $fillable = [
        'legal_name', 'trade_name', 'document', 'slug', 'email', 'phone',
        'status', 'saas_plan_id', 'trial_ends_at', 'settings',
    ];

    protected $attributes = ['settings' => '{}', 'status' => self::STATUS_TRIAL];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class, 'saas_plan_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScopes();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class)->withoutGlobalScopes();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class)->withoutGlobalScopes();
    }

    /** A empresa pode operar? (trial válido ou ativa) */
    public function isOperational(): bool
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => true,
            self::STATUS_TRIAL => $this->trial_ends_at === null || $this->trial_ends_at->isFuture(),
            default => false,
        };
    }

    /**
     * Acesso "só cobrança": bloqueada por falta de pagamento ou teste grátis encerrado. O
     * administrador (assinatura.gerenciar) entra apenas para escolher o plano e pagar.
     */
    public function billingLocked(): bool
    {
        if ($this->isOperational()) {
            return false;
        }
        $sub = DB::table('subscriptions')->where('company_id', $this->id)->value('status');

        return ($this->status === self::STATUS_SUSPENDED && $sub === 'suspended') || ($this->status === self::STATUS_TRIAL && $sub === 'trialing');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
