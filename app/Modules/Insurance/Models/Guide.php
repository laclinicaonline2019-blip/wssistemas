<?php

namespace App\Modules\Insurance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientInsurance;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Guia de atendimento do convênio (TISS: guia de consulta ou SP/SADT).
 * Rascunho → pronta → faturada (em lote) → paga / paga parcial (glosa) / negada.
 * Depois de faturada, os dados e itens não mudam (só o retorno da operadora).
 */
class Guide extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const TYPES = ['consulta' => 'Consulta', 'sp_sadt' => 'SP/SADT (exames e procedimentos)'];

    public const STATUSES = [
        'draft' => 'Rascunho', 'ready' => 'Pronta para faturar', 'billed' => 'Faturada', 'paid' => 'Paga',
        'partial' => 'Paga com glosa', 'denied' => 'Glosada (total)', 'cancelled' => 'Cancelada',
    ];

    public const GLOSA_STATUSES = ['pending' => 'Glosa a analisar', 'appealed' => 'Em recurso', 'accepted' => 'Glosa aceita', 'recovered' => 'Recurso concluído'];

    public const CONSULTATION_TYPES = ['1' => 'Primeira consulta', '2' => 'Seguimento (retorno)', '3' => 'Pré-natal', '4' => 'Por encaminhamento'];

    public const ACCIDENT = ['9' => 'Não acidente', '0' => 'Acidente de trabalho', '1' => 'Acidente de trânsito', '2' => 'Outros acidentes'];

    /** Campos que só podem mudar enquanto a guia não foi faturada. */
    private const LOCKED_AFTER_BILLING = [
        'insurer_id', 'plan_id', 'patient_id', 'patient_insurance_id', 'doctor_id', 'guide_type', 'number', 'card_number', 'card_valid_until',
        'cbo_code', 'attendance_date', 'consultation_type', 'attendance_type', 'accident_indicator', 'character', 'clinical_indication',
        'authorization_id', 'operator_guide_number', 'total_cents',
    ];

    protected $table = 'insurance_guides';

    protected string $auditName = 'insurance_guide';

    protected $fillable = [
        'branch_id', 'insurer_id', 'plan_id', 'patient_id', 'patient_insurance_id', 'appointment_id', 'doctor_id', 'authorization_id',
        'guide_type', 'number', 'operator_guide_number', 'card_number', 'card_valid_until', 'cbo_code', 'attendance_date',
        'consultation_type', 'attendance_type', 'accident_indicator', 'character', 'clinical_indication', 'observation', 'created_by',
    ];

    protected $attributes = ['status' => 'draft', 'total_cents' => 0, 'paid_cents' => 0, 'glosa_cents' => 0, 'consultation_type' => '1', 'attendance_type' => '04', 'accident_indicator' => '9', 'character' => '1'];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date', 'card_valid_until' => 'date',
            'total_cents' => 'integer', 'paid_cents' => 'integer', 'glosa_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Guias não são excluídas — cancele com motivo.'));
        static::updating(function (Guide $guide) {
            $locked = in_array($guide->getOriginal('status'), ['billed', 'paid', 'partial', 'denied'], true)
                || ($guide->getOriginal('batch_id') !== null && $guide->batch_id !== null);
            if ($locked && $guide->isDirty(self::LOCKED_AFTER_BILLING)) {
                throw new LogicException('Guia em lote/faturada não pode ser alterada.');
            }
        });
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'ready'], true) && $this->batch_id === null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function scopeAccessibleBranches(Builder $q, ?array $branchIds): Builder
    {
        return $branchIds === null ? $q : $q->whereIn('branch_id', $branchIds);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GuideItem::class)->orderBy('created_at');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InsurancePlan::class, 'plan_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class, 'patient_insurance_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(Authorization::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function copayReceivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class, 'copay_receivable_id');
    }
}
