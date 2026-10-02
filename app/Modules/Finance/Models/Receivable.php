<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Conta a receber. Pago/saldo derivam das movimentações (nunca editados à mão). */
class Receivable extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const STATUSES = ['open' => 'Em aberto', 'partial' => 'Parcial', 'paid' => 'Recebido', 'cancelled' => 'Cancelado'];

    protected string $auditName = 'receivable';

    protected $fillable = [
        'branch_id', 'category_id', 'patient_id', 'appointment_id', 'doctor_id', 'description', 'origin', 'payer_type',
        'amount_cents', 'due_date', 'notes', 'created_by', 'insurance_guide_id',
    ];

    protected $attributes = ['status' => 'open', 'discount_cents' => 0, 'paid_cents' => 0, 'origin' => 'manual', 'payer_type' => 'private'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer', 'discount_cents' => 'integer', 'paid_cents' => 'integer',
            'due_date' => 'date', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Contas não são excluídas — cancele com motivo.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class)->orderBy('occurred_at');
    }

    public function balanceCents(): int
    {
        return $this->status === 'cancelled' ? 0 : $this->amount_cents - $this->discount_cents - $this->paid_cents;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['open', 'partial'], true) && $this->due_date->toDateString() < now('America/Sao_Paulo')->toDateString();
    }

    public function statusLabel(): string
    {
        return $this->isOverdue() ? 'Vencido' : self::STATUSES[$this->status];
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['open', 'partial']);
    }

    /** Restringe às unidades que o usuário pode acessar (null = todas). */
    public function scopeAccessibleBranches(Builder $q, ?array $branchIds): Builder
    {
        return $branchIds === null ? $q : $q->where(fn ($w) => $w->whereIn($this->getTable().'.branch_id', $branchIds));
    }
}
