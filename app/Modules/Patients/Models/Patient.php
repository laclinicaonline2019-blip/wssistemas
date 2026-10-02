<?php

namespace App\Modules\Patients\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Format;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Paciente. Cadastro compartilhado entre as filiais da empresa.
 * Nunca é excluído fisicamente (retenção legal do prontuário): inativação ou
 * anonimização (LGPD) são as únicas formas de "remoção".
 */
class Patient extends Model
{
    use Auditable, BelongsToCompany, HasUlids, SoftDeletes;

    public const SEXES = ['F' => 'Feminino', 'M' => 'Masculino', 'I' => 'Intersexo'];

    protected string $auditName = 'patient';

    protected array $auditExclude = ['search_name', 'active_cpf'];

    protected $fillable = [
        'home_branch_id', 'name', 'social_name', 'cpf', 'rg', 'rg_issuer', 'cns', 'birth_date', 'sex',
        'gender_identity', 'mother_name', 'phone', 'whatsapp', 'email', 'zip_code', 'street', 'number',
        'complement', 'district', 'city', 'state', 'preferred_contact', 'notes', 'status', 'deceased_at',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'deceased_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'record_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Patient $patient) {
            $patient->search_name = Format::searchable(trim(($patient->name ?? '').' '.($patient->social_name ?? '')));
        });

        // Proteção final contra exclusão física de dados clínicos.
        static::forceDeleting(fn () => throw new \LogicException('Pacientes não podem ser excluídos fisicamente (retenção legal).'));
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PatientContact::class)->orderBy('type')->orderBy('name');
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class)->where('is_active', true)->orderByDesc('is_primary')->orderBy('insurer_name');
    }

    /** Inclui carteirinhas removidas do cadastro (mantidas por estarem em guias/autorizações). */
    public function allInsurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function displayName(): string
    {
        return $this->social_name ?: $this->name;
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    public function isMinor(): bool
    {
        return $this->birth_date !== null && $this->birth_date->age < 18;
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /** Estado atual de cada consentimento (último registro por finalidade). */
    public function currentConsents(): array
    {
        $state = [];

        foreach ($this->consents as $consent) {
            $state[$consent->purpose] ??= $consent;
        }

        return $state;
    }

    public function hasConsent(string $purpose): bool
    {
        return (bool) ($this->currentConsents()[$purpose]->granted ?? false);
    }

    /**
     * Busca por nome (sem acento), CPF, telefone/WhatsApp ou nº de prontuário.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $digits = Format::digits($term);

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('search_name', 'like', '%'.addcslashes(Format::searchable($term), '%_\\').'%');

            if ($digits !== null) {
                if (strlen($digits) === 11) {
                    $q->orWhere('cpf', $digits);
                }
                if (strlen($digits) >= 8) {
                    $q->orWhere('phone', 'like', '%'.$digits)->orWhere('whatsapp', 'like', '%'.$digits);
                }
                if (strlen($digits) <= 10 && $digits === ltrim($term, '#')) {
                    $q->orWhere('record_number', (int) $digits);
                }
            }
        });
    }
}
