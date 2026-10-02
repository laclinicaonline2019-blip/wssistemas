<?php

namespace App\Modules\Insurance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientInsurance;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Autorização prévia (senha) da operadora para um procedimento. */
class Authorization extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = ['requested' => 'Solicitada', 'authorized' => 'Autorizada', 'denied' => 'Negada', 'cancelled' => 'Cancelada', 'used' => 'Utilizada'];

    protected $table = 'insurance_authorizations';

    protected string $auditName = 'insurance_authorization';

    protected $fillable = [
        'branch_id', 'patient_id', 'patient_insurance_id', 'insurer_id', 'procedure_id', 'doctor_id', 'quantity', 'status',
        'operator_guide_number', 'password', 'authorized_on', 'valid_until', 'denial_reason', 'notes', 'created_by', 'decided_by', 'decided_at',
    ];

    protected $attributes = ['status' => 'requested', 'quantity' => 1];

    protected function casts(): array
    {
        return ['authorized_on' => 'date', 'valid_until' => 'date', 'decided_at' => 'datetime', 'quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Autorizações não são excluídas — cancele.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class, 'patient_insurance_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Autorizada e dentro da validade na data informada (Y-m-d). */
    public function isUsableOn(string $date): bool
    {
        return $this->status === 'authorized' && ($this->valid_until === null || $this->valid_until->toDateString() >= $date);
    }
}
