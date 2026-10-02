<?php

namespace App\Modules\Scheduling\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tipo de atendimento oferecido pelo médico (consulta, retorno…) e seu valor particular. */
class DoctorService extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    protected string $auditName = 'doctor_service';

    protected $fillable = ['doctor_id', 'name', 'duration_minutes', 'price_cents', 'accepts_private', 'accepts_insurance', 'is_telemedicine', 'is_return', 'is_active', 'procedure_id'];

    protected $attributes = ['is_active' => true, 'accepts_private' => true, 'accepts_insurance' => false, 'is_telemedicine' => false, 'is_return' => false, 'price_cents' => 0];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer', 'duration_minutes' => 'integer', 'accepts_private' => 'boolean',
            'accepts_insurance' => 'boolean', 'is_telemedicine' => 'boolean', 'is_return' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function priceFormatted(): string
    {
        return 'R$ '.number_format($this->price_cents / 100, 2, ',', '.');
    }
}
