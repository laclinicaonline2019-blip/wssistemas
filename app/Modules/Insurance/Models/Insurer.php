<?php

namespace App\Modules\Insurance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Convênio / operadora de plano de saúde. Nunca excluído — desativado. */
class Insurer extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const TISS_VERSIONS = ['4.01.00'];

    protected string $auditName = 'insurer';

    protected $fillable = ['name', 'ans_registry', 'cnpj', 'provider_code', 'tiss_version', 'payment_term_days', 'max_guides_per_batch', 'phone', 'email', 'portal_url', 'notes', 'is_active'];

    protected $attributes = ['is_active' => true, 'tiss_version' => '4.01.00', 'payment_term_days' => 30, 'max_guides_per_batch' => 100];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_term_days' => 'integer', 'max_guides_per_batch' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Convênios não são excluídos — desative.'));
    }

    public function plans(): HasMany
    {
        return $this->hasMany(InsurancePlan::class);
    }

    public function priceTables(): HasMany
    {
        return $this->hasMany(PriceTable::class);
    }

    /** Médicos credenciados (nenhum = todos os médicos atendem o convênio). */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'insurer_doctor')->withPivotValue('company_id', $this->company_id);
    }

    public function acceptsDoctor(string $doctorId): bool
    {
        $credentialed = $this->doctors()->pluck('doctors.id');

        return $credentialed->isEmpty() || $credentialed->contains($doctorId);
    }

    /** Pendências cadastrais que impedem gerar o XML TISS. */
    public function tissIssues(): array
    {
        return array_values(array_filter([
            preg_match('/^\d{6}$/', (string) $this->ans_registry) ? null : 'registro ANS (6 dígitos)',
            $this->provider_code || $this->cnpj ? null : 'código do prestador na operadora',
        ]));
    }
}
