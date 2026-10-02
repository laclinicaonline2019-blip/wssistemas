<?php

namespace App\Modules\Payments\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Insurance\Models\Insurer;
use App\Modules\Scheduling\Models\DoctorService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Regra de repasse/split do médico (percentual ou valor fixo; por tipo de atendimento e pagador). */
class SplitRule extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    protected string $auditName = 'split_rule';

    protected $fillable = ['doctor_id', 'doctor_service_id', 'payer_type', 'insurer_id', 'type', 'value', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['value' => 'integer', 'is_active' => 'boolean'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(DoctorService::class, 'doctor_service_id');
    }

    /** Parte do médico sobre a base (arredondamento comercial, nunca acima da base). */
    public function shareOf(int $base): int
    {
        return $this->type === 'percent' ? intdiv($base * $this->value + 5000, 10000) : min($this->value, $base);
    }

    public function label(): string
    {
        return $this->type === 'percent' ? number_format($this->value / 100, 2, ',', '.').'%' : 'R$ '.number_format($this->value / 100, 2, ',', '.');
    }
}
