<?php

namespace App\Modules\Patients\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Insurance\Models\InsurancePlan;
use App\Modules\Insurance\Models\Insurer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** Carteirinha do paciente. Ligada ao convênio/plano cadastrados (Fase 9); insurer_name guarda o nome exibido. */
class PatientInsurance extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['patient_id', 'insurer_id', 'plan_id', 'insurer_name', 'plan_name', 'card_number', 'valid_until', 'is_primary', 'is_active'];

    protected $attributes = ['is_active' => true, 'is_primary' => false];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'is_primary' => 'boolean', 'is_active' => 'boolean'];
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InsurancePlan::class, 'plan_id');
    }

    public function isExpired(?string $onDate = null): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return $onDate !== null ? $this->valid_until->toDateString() < $onDate : $this->valid_until->endOfDay()->isPast();
    }

    /** Usada em guia, autorização ou agendamento — não pode ser apagada, só desativada. */
    public function isReferenced(): bool
    {
        return DB::table('insurance_guides')->where('patient_insurance_id', $this->id)->exists()
            || DB::table('insurance_authorizations')->where('patient_insurance_id', $this->id)->exists()
            || DB::table('appointments')->where('patient_insurance_id', $this->id)->exists();
    }
}
