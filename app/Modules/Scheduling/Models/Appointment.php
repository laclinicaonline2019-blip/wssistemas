<?php

namespace App\Modules\Scheduling\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientInsurance;
use App\Modules\Queue\Models\QueueTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = [
        'scheduled' => 'Agendado', 'confirmed' => 'Confirmado', 'arrived' => 'Chegou', 'in_service' => 'Em atendimento',
        'completed' => 'Realizado', 'cancelled' => 'Cancelado', 'no_show' => 'Faltou',
    ];

    /** Status que ocupam horário. */
    public const ACTIVE = ['scheduled', 'confirmed', 'arrived', 'in_service', 'completed'];

    /** Transições permitidas (máquina de estados). */
    public const TRANSITIONS = [
        'scheduled' => ['confirmed', 'arrived', 'cancelled', 'no_show'],
        'confirmed' => ['arrived', 'cancelled', 'no_show', 'scheduled'],
        'arrived' => ['in_service', 'completed', 'cancelled', 'no_show'],
        'in_service' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    public const CHANNELS = ['reception' => 'Recepção', 'phone' => 'Telefone', 'whatsapp' => 'WhatsApp', 'ai' => 'Assistente virtual', 'portal' => 'Portal do paciente', 'api' => 'Integração'];

    protected string $auditName = 'appointment';

    protected array $auditExclude = ['holds_slot'];

    protected $fillable = [
        'branch_id', 'doctor_id', 'patient_id', 'service_id', 'template_id', 'room_id', 'starts_at', 'ends_at',
        'is_overbook', 'channel', 'payer_type', 'patient_insurance_id', 'price_cents', 'notes', 'idempotency_key',
    ];

    protected $attributes = ['status' => 'scheduled', 'is_overbook' => false, 'channel' => 'reception', 'payer_type' => 'private', 'price_cents' => 0];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'confirmed_at' => 'datetime', 'arrived_at' => 'datetime',
            'started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'is_overbook' => 'boolean', 'price_cents' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(DoctorService::class, 'service_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function insurance(): BelongsTo
    {
        return $this->belongsTo(PatientInsurance::class, 'patient_insurance_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(QueueTicket::class)->latestOfMany();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function scopeAccessible(Builder $query, ?array $branchIds): Builder
    {
        return $branchIds === null ? $query : $query->whereIn($this->qualifyColumn('branch_id'), $branchIds);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function priceFormatted(): string
    {
        return 'R$ '.number_format($this->price_cents / 100, 2, ',', '.');
    }
}
