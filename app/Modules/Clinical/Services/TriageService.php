<?php

namespace App\Modules\Clinical\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Clinical\Models\Triage;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;

class TriageService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function record(User $actor, array $data): Triage
    {
        $patient = Patient::query()->whereKey($data['patient_id'])->whereNull('anonymized_at')->first()
            ?? throw new BusinessRuleViolation('Paciente inválido.', 'invalid_patient');

        $branchId = $data['branch_id'] ?? $this->context->branchId();

        if (! empty($data['appointment_id'])) {
            $appointment = Appointment::query()->where('patient_id', $patient->id)->whereKey($data['appointment_id'])->first()
                ?? throw new BusinessRuleViolation('Agendamento inválido para este paciente.', 'invalid_appointment');
            $branchId = $appointment->branch_id;
        }

        if (! $branchId || ! Branch::query()->accessible($this->context->allowedBranchIds())->whereKey($branchId)->exists()) {
            throw new BusinessRuleViolation('Selecione uma unidade válida.', 'invalid_branch');
        }

        $triage = Triage::create([...$data, 'patient_id' => $patient->id, 'branch_id' => $branchId, 'recorded_by' => $actor->id]);

        // Sem conteúdo clínico na trilha: apenas o fato e a referência.
        $this->audit->record('triage.recorded', $triage, metadata: ['patient_id' => $patient->id, 'risk' => $triage->risk]);

        return $triage;
    }

    public function latestToday(string $patientId): ?Triage
    {
        return Triage::query()->where('patient_id', $patientId)
            ->where('created_at', '>=', now('America/Sao_Paulo')->startOfDay()->utc())
            ->latest('created_at')->first();
    }
}
