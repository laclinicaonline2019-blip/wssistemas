<?php

namespace App\Modules\Identity\Models;

use App\Core\Access\PermissionService;
use App\Core\Audit\Auditable;
use App\Core\Tenancy\Exceptions\CrossTenantViolation;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantUserScope;
use App\Modules\Platform\Models\Company;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_INVITED = 'invited';

    protected string $auditName = 'user';

    /** Campos operacionais de segurança que não precisam poluir a trilha. */
    protected array $auditExclude = ['remember_token', 'last_login_at', 'last_login_ip', 'failed_login_attempts'];

    /** company_id e is_super_admin NÃO são mass-assignable (definidos só pelos serviços). */
    protected $fillable = ['name', 'email', 'phone', 'password', 'status', 'must_change_password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $attributes = ['status' => self::STATUS_ACTIVE, 'is_super_admin' => false];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantUserScope);

        static::saving(function (User $user) {
            $user->email = mb_strtolower(trim($user->email));

            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
            }
        });

        static::creating(function (User $user) {
            $context = app(TenantContext::class);

            if ($context->hasCompany() && ! $context->isSystem()) {
                if ($user->is_super_admin || ($user->company_id && $user->company_id !== $context->companyId())) {
                    throw new CrossTenantViolation;
                }
                $user->company_id = $context->companyId();
            }
        });

        static::updating(function (User $user) {
            if ($user->isDirty('company_id') || $user->isDirty('is_super_admin')) {
                throw new CrossTenantViolation('company_id/is_super_admin são imutáveis após a criação.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function roleAssignments(): HasMany
    {
        // A FK composta (company_id, user_id) garante que os vínculos são da mesma empresa do usuário.
        return $this->hasMany(RoleAssignment::class)->withoutGlobalScopes();
    }

    /**
     * Usuários que um gestor restrito às filiais $allowed pode gerenciar:
     * todos os vínculos dentro dessas filiais. null = sem restrição.
     */
    public function scopeManageableBy(Builder $query, ?array $allowed): Builder
    {
        if ($allowed === null) {
            return $query;
        }

        return $query
            ->whereHas('roleAssignments', fn ($q) => $q->whereIn('branch_id', $allowed))
            ->whereDoesntHave('roleAssignments', fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereNotIn('branch_id', $allowed)));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function hasPermission(string $permission, ?string $branchId = null): bool
    {
        return app(PermissionService::class)->userHas($this, $permission, $branchId);
    }

    /** @return list<string>|null null = todas as filiais */
    public function allowedBranchIds(): ?array
    {
        return app(PermissionService::class)->allowedBranchIds($this);
    }
}
