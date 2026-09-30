<?php

namespace App\Modules\Scheduling\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Organization\Models\Branch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Período de atendimento recorrente (ex.: toda segunda, 08:00–12:00, slots de 20 min). */
class ScheduleTemplate extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const WEEKDAYS = [0 => 'Domingo', 1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];

    protected string $auditName = 'schedule_template';

    protected $fillable = ['doctor_id', 'branch_id', 'room_id', 'specialty_id', 'weekday', 'start_time', 'end_time', 'slot_minutes', 'max_patients', 'max_overbooks', 'valid_from', 'valid_until', 'is_active'];

    protected $attributes = ['is_active' => true, 'max_overbooks' => 0];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer', 'slot_minutes' => 'integer', 'max_patients' => 'integer', 'max_overbooks' => 'integer',
            'valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Vale na data (dia da semana e vigência)? $date no fuso da filial. */
    public function appliesOn(CarbonInterface $date): bool
    {
        return $this->is_active
            && $this->weekday === $date->dayOfWeek
            && ($this->valid_from === null || $date->toDateString() >= $this->valid_from->toDateString())
            && ($this->valid_until === null || $date->toDateString() <= $this->valid_until->toDateString());
    }

    public function slotCount(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));

        return intdiv(($eh * 60 + $em) - ($sh * 60 + $sm), $this->slot_minutes);
    }

    public function capacity(): int
    {
        return $this->max_patients ?? $this->slotCount();
    }

    public function label(): string
    {
        return self::WEEKDAYS[$this->weekday].' '.substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }
}
