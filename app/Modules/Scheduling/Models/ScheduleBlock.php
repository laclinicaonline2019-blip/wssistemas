<?php

namespace App\Modules\Scheduling\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Indisponibilidade: férias, congresso, manutenção. Sem médico = unidade inteira. */
class ScheduleBlock extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const TYPES = ['vacation' => 'Férias', 'block' => 'Bloqueio', 'event' => 'Congresso/evento', 'maintenance' => 'Manutenção'];

    protected string $auditName = 'schedule_block';

    protected $fillable = ['doctor_id', 'branch_id', 'starts_at', 'ends_at', 'type', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
