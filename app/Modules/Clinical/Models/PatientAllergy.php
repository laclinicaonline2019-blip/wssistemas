<?php

namespace App\Modules\Clinical\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAllergy extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const SEVERITIES = ['mild' => 'Leve', 'moderate' => 'Moderada', 'severe' => 'Grave', 'unknown' => 'Não informada'];

    protected string $auditName = 'patient_allergy';

    protected $fillable = ['patient_id', 'substance', 'reaction', 'severity', 'status', 'recorded_by'];

    protected $attributes = ['severity' => 'unknown', 'status' => 'active'];

    /** Registra uma alergia, recusando duplicata ativa da mesma substância (sem diferenciar acento/caixa). */
    public static function record(string $patientId, array $data, string $userId): self
    {
        $key = Format::searchable($data['substance']);
        $duplicate = self::query()->where('patient_id', $patientId)->where('status', 'active')->pluck('substance')
            ->contains(fn ($s) => Format::searchable($s) === $key);

        if ($duplicate) {
            throw new BusinessRuleViolation('Esta alergia já está registrada para o paciente.', 'duplicate_allergy');
        }

        return self::create($data + ['patient_id' => $patientId, 'recorded_by' => $userId]);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
