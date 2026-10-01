<?php

namespace App\Modules\Clinical\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Atendimento. Enquanto "draft", o conteúdo fica em draft_data (autosave).
 * Ao finalizar, o conteúdo vira uma EncounterVersion imutável; depois disso
 * só são possíveis adendos (novas versões com justificativa).
 */
class Encounter extends Model
{
    use BelongsToCompany, HasUlids;

    /** Seções do registro clínico (ordem de exibição). */
    public const SECTIONS = [
        'chief_complaint' => 'Queixa principal',
        'history' => 'História da doença atual (anamnese)',
        'past_history' => 'Antecedentes pessoais e familiares',
        'medications_in_use' => 'Medicamentos em uso',
        'vital_signs' => 'Sinais vitais',
        'physical_exam' => 'Exame físico',
        'assessment' => 'Avaliação / hipótese diagnóstica',
        'conduct' => 'Conduta',
        'exam_requests' => 'Exames solicitados',
        'guidance' => 'Orientações ao paciente',
        'notes' => 'Observações',
    ];

    protected $fillable = ['branch_id', 'patient_id', 'doctor_id', 'appointment_id', 'specialty_id', 'started_at', 'created_by'];

    protected $attributes = ['status' => 'draft', 'draft_revision' => 0, 'current_version' => 0];

    protected function casts(): array
    {
        return [
            'draft_data' => 'array', 'draft_revision' => 'integer', 'current_version' => 'integer',
            'draft_saved_at' => 'datetime', 'started_at' => 'datetime', 'finalized_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EncounterVersion::class)->orderBy('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(EncounterVersion::class)->latestOfMany('version');
    }

    public function originalVersion(): HasOne
    {
        return $this->hasOne(EncounterVersion::class)->where('kind', 'original');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(EncounterDiagnosis::class)->orderByDesc('is_primary');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
