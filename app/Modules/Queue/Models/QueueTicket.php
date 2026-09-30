<?php

namespace App\Modules\Queue\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Room;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Senha de atendimento (A001, P002…), numerada por unidade/dia/tipo. */
class QueueTicket extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = ['waiting' => 'Aguardando', 'called' => 'Chamado', 'in_service' => 'Em atendimento', 'done' => 'Finalizado', 'skipped' => 'Não compareceu', 'cancelled' => 'Cancelado'];

    public const OPEN = ['waiting', 'called', 'in_service'];

    protected string $auditName = 'queue_ticket';

    protected $fillable = ['branch_id', 'appointment_id', 'patient_id', 'doctor_id', 'room_id', 'service_date', 'type', 'prefix', 'number', 'code', 'is_priority', 'status', 'arrived_at', 'created_by', 'notes'];

    protected $attributes = ['status' => 'waiting', 'is_priority' => false, 'call_count' => 0];

    protected function casts(): array
    {
        return [
            'service_date' => 'date', 'arrived_at' => 'datetime', 'called_at' => 'datetime', 'started_at' => 'datetime',
            'finished_at' => 'datetime', 'is_priority' => 'boolean', 'number' => 'integer', 'call_count' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Minutos de espera até a chamada (ou até agora). */
    public function waitMinutes(): int
    {
        return (int) $this->arrived_at->diffInMinutes($this->called_at ?? now(), true);
    }
}
