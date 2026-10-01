<?php

namespace App\Modules\Clinical\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Triagem / sinais vitais. Imutável: uma correção é registrada como nova triagem. */
class Triage extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    public const RISKS = [
        'vermelho' => 'Emergência (vermelho)', 'laranja' => 'Muito urgente (laranja)', 'amarelo' => 'Urgente (amarelo)',
        'verde' => 'Pouco urgente (verde)', 'azul' => 'Não urgente (azul)',
    ];

    public const VITALS = [
        'bp' => 'PA', 'heart_rate' => 'FC', 'respiratory_rate' => 'FR', 'temperature' => 'Tax', 'spo2' => 'SpO₂',
        'weight_kg' => 'Peso', 'height_cm' => 'Altura', 'glucose' => 'Glicemia', 'pain_scale' => 'Dor',
    ];

    protected $fillable = [
        'branch_id', 'patient_id', 'appointment_id', 'recorded_by', 'bp_systolic', 'bp_diastolic', 'heart_rate',
        'respiratory_rate', 'temperature', 'spo2', 'weight_kg', 'height_cm', 'glucose', 'pain_scale', 'risk',
        'chief_complaint', 'notes',
    ];

    protected function casts(): array
    {
        return ['temperature' => 'float', 'weight_kg' => 'float', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Triagem é imutável: registre uma nova.'));
        static::deleting(fn () => throw new LogicException('Triagem é imutável.'));
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function bmi(): ?float
    {
        return $this->weight_kg && $this->height_cm ? round($this->weight_kg / (($this->height_cm / 100) ** 2), 1) : null;
    }

    /** Resumo legível: "PA 120/80 · FC 72 · Tax 36,5 °C · SpO₂ 98%". */
    public function summary(): string
    {
        return collect([
            $this->bp_systolic ? "PA {$this->bp_systolic}/{$this->bp_diastolic} mmHg" : null,
            $this->heart_rate ? "FC {$this->heart_rate} bpm" : null,
            $this->respiratory_rate ? "FR {$this->respiratory_rate} irpm" : null,
            $this->temperature ? 'Tax '.number_format($this->temperature, 1, ',', '').' °C' : null,
            $this->spo2 ? "SpO₂ {$this->spo2}%" : null,
            $this->weight_kg ? 'Peso '.number_format($this->weight_kg, 1, ',', '').' kg' : null,
            $this->height_cm ? "Altura {$this->height_cm} cm" : null,
            $this->bmi() ? 'IMC '.number_format($this->bmi(), 1, ',', '') : null,
            $this->glucose ? "Glicemia {$this->glucose} mg/dL" : null,
            $this->pain_scale !== null ? "Dor {$this->pain_scale}/10" : null,
        ])->filter()->implode(' · ');
    }
}
