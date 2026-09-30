<?php

namespace App\Modules\Scheduling\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = ['active' => 'Ativa', 'inactive' => 'Inativa', 'maintenance' => 'Em manutenção'];

    protected string $auditName = 'room';

    protected $fillable = ['branch_id', 'name', 'number', 'specialty_id', 'doctor_id', 'equipment', 'status'];

    protected $attributes = ['status' => 'active'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Texto exibido no painel: "Sala 03" / "Consultório Azul". */
    public function label(): string
    {
        return $this->number ? trim($this->name.' '.$this->number) : $this->name;
    }
}
