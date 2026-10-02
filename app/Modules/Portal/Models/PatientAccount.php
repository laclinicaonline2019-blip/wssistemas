<?php

namespace App\Modules\Portal\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Acesso do paciente ao portal. Separado dos usuários da clínica (guard "patient"):
 * nunca recebe perfis/permissões e só enxerga dados do próprio paciente.
 */
class PatientAccount extends Authenticatable
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = ['invited' => 'Convite enviado', 'active' => 'Ativo', 'blocked' => 'Bloqueado'];

    protected string $auditName = 'patient_account';

    protected array $auditExclude = ['password', 'remember_token', 'failed_login_attempts', 'last_login_at', 'last_login_ip'];

    protected $fillable = ['patient_id', 'email', 'created_by'];

    protected $hidden = ['password'];

    protected $attributes = ['status' => 'invited', 'failed_login_attempts' => 0];

    protected function casts(): array
    {
        return [
            'password' => 'hashed', 'locked_until' => 'datetime', 'activated_at' => 'datetime', 'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->password !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Sem "lembrar-me" no portal (dados de saúde): sessão expira normalmente. */
    public function getRememberTokenName()
    {
        return '';
    }
}
