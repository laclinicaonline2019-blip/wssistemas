<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\SequenceGenerator;
use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Insurance\Services\InsuranceCatalog;
use App\Modules\Messaging\Services\AppointmentNotifier;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientInsurance;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Criação e remarcação de agendamentos com garantia de não haver dupla marcação.
 *
 * Toda reserva acontece numa transação que trava a linha do médico
 * (SELECT … FOR UPDATE): reservas simultâneas para o mesmo médico são
 * serializadas e cada uma revalida disponibilidade, limites do período,
 * limite diário e encaixes com os dados já confirmados pelas anteriores.
 * O índice único (médico, início) é a barreira final no banco.
 */
class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly SequenceGenerator $sequences,
        private readonly InsuranceCatalog $insuranceCatalog,
        private readonly AppointmentNotifier $notifier,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{doctor_id: string, branch_id: string, patient_id: string, starts_at: string|CarbonImmutable,
     *               service_id?: ?string, is_overbook?: bool, payer_type?: string, patient_insurance_id?: ?string,
     *               channel?: string, notes?: ?string, idempotency_key?: ?string}  $data
     */
    public function book(?User $actor, array $data): Appointment
    {
        if (! empty($data['idempotency_key'])) {
            $existing = Appointment::query()->where('idempotency_key', $data['idempotency_key'])->first();

            if ($existing) {
                return $this->idempotentReplay($existing, $data);
            }
        }

        try {
            $booked = DB::transaction(function () use ($actor, $data) {
                $doctor = $this->lockDoctor($data['doctor_id']);
                $branch = $this->branch($data['branch_id']);
                $patient = $this->patient($data['patient_id']);
                $service = $this->service($doctor, $data['service_id'] ?? null);
                $overbook = (bool) ($data['is_overbook'] ?? false);

                // Encaixe só por usuário com permissão — nunca por canais automáticos (portal, WhatsApp, IA).
                if ($overbook && ($actor === null || ! $actor->hasPermission('agenda.encaixe', $branch->id))) {
                    throw new BusinessRuleViolation('Você não tem permissão para realizar encaixes.', 'overbook_forbidden', 403);
                }

                [$start, $end, $template] = $this->validateTime($doctor, $branch, $data['starts_at'], $service, $overbook);
                $this->ensurePatientFree($patient, $start, $end);
                [$payer, $insuranceId, $price] = $this->pricing($patient, $service, $data['payer_type'] ?? 'private', $data['patient_insurance_id'] ?? null, $doctor->id, $start->timezone($branch->timezone ?: 'America/Sao_Paulo')->toDateString());

                $appointment = new Appointment([
                    'branch_id' => $branch->id,
                    'doctor_id' => $doctor->id,
                    'patient_id' => $patient->id,
                    'service_id' => $service?->id,
                    'template_id' => $template->id,
                    'room_id' => $template->room_id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'is_overbook' => $overbook,
                    'channel' => $data['channel'] ?? 'reception',
                    'payer_type' => $payer,
                    'patient_insurance_id' => $insuranceId,
                    'price_cents' => $price,
                    'notes' => $data['notes'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ]);
                $appointment->protocol = $this->protocol();
                $appointment->created_by = $actor?->id;
                $appointment->save();

                return $appointment;
            });
            $this->notifier->booked($booked);

            return $booked;
        } catch (UniqueConstraintViolationException $e) {
            // Corrida resolvida pelo banco: outro agendamento gravou o mesmo horário/chave.
            if (! empty($data['idempotency_key']) && ($existing = Appointment::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
                return $this->idempotentReplay($existing, $data);
            }

            throw new BusinessRuleViolation('Este horário acabou de ser ocupado. Escolha outro horário.', 'slot_taken', 409);
        }
    }

    /** Remarca mantendo o protocolo (mesmas validações da reserva). */
    public function reschedule(User $actor, Appointment $appointment, string|CarbonImmutable $newStart, ?string $newDoctorId = null, ?string $newBranchId = null): Appointment
    {
        if (! in_array($appointment->status, ['scheduled', 'confirmed'], true)) {
            throw new BusinessRuleViolation('Somente agendamentos pendentes ou confirmados podem ser remarcados.', 'invalid_status');
        }

        try {
            $moved = DB::transaction(function () use ($appointment, $newStart, $newDoctorId, $newBranchId) {
                $doctor = $this->lockDoctor($newDoctorId ?? $appointment->doctor_id);
                $branch = $this->branch($newBranchId ?? $appointment->branch_id);
                $service = $appointment->service_id && $doctor->id === $appointment->doctor_id ? $appointment->service : null;

                [$start, $end, $template] = $this->validateTime($doctor, $branch, $newStart, $service, $appointment->is_overbook, $appointment->id);
                $this->ensurePatientFree($appointment->patient, $start, $end, $appointment->id);

                $old = ['starts_at' => $appointment->starts_at->toIso8601String(), 'doctor_id' => $appointment->doctor_id, 'branch_id' => $appointment->branch_id];
                $appointment->forceFill([
                    'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'starts_at' => $start, 'ends_at' => $end,
                    'template_id' => $template->id, 'room_id' => $template->room_id, 'service_id' => $service?->id,
                    'status' => 'scheduled', 'confirmed_at' => null,
                ])->save();

                $this->audit->record('appointment.rescheduled', $appointment, old: $old, new: ['starts_at' => $start->toIso8601String(), 'doctor_id' => $doctor->id, 'branch_id' => $branch->id]);

                return $appointment;
            });
            $this->notifier->rescheduled($moved);

            return $moved;
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('Este horário acabou de ser ocupado. Escolha outro horário.', 'slot_taken', 409);
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ScheduleTemplate}
     */
    private function validateTime(Doctor $doctor, Branch $branch, string|CarbonImmutable $startsAt, ?DoctorService $service, bool $overbook, ?string $ignoreId = null): array
    {
        if ($doctor->status !== 'active') {
            throw new BusinessRuleViolation('Médico inativo.', 'doctor_inactive');
        }

        if (! $doctor->branches()->where('branches.id', $branch->id)->exists()) {
            throw new BusinessRuleViolation('O médico não atende nesta unidade.', 'doctor_not_in_branch');
        }

        $start = ($startsAt instanceof CarbonImmutable ? $startsAt : CarbonImmutable::parse($startsAt, $branch->timezone))->utc()->startOfMinute();
        $local = $start->setTimezone($branch->timezone);

        if ($start->isPast()) {
            throw new BusinessRuleViolation('Não é possível agendar em horário passado.', 'past_time');
        }

        foreach ($this->availability->day($doctor, $branch, $local, $service?->duration_minutes, $ignoreId) as $period) {
            [$periodStart, $periodEnd] = $this->availability->periodBounds($period['template'], $local);

            if ($start < $periodStart || $start >= $periodEnd) {
                continue;
            }

            if ($overbook) {
                if ($period['overbooks'] >= $period['template']->max_overbooks) {
                    throw new BusinessRuleViolation('Limite de encaixes do período atingido.', 'overbook_limit');
                }

                $slot = collect($period['slots'])->first(fn (Slot $s) => $s->start->equalTo($start));
                if ($slot && in_array($slot->status, [Slot::BLOCKED, Slot::PAST], true)) {
                    throw new BusinessRuleViolation('Horário indisponível: '.($slot->reason ?? 'bloqueado'), 'slot_unavailable');
                }

                $end = $start->addMinutes($service?->duration_minutes ?? $period['template']->slot_minutes);

                return [$start, $end, $period['template']];
            }

            $slot = collect($period['slots'])->first(fn (Slot $s) => $s->start->equalTo($start));

            if (! $slot) {
                throw new BusinessRuleViolation('Horário fora da grade do médico.', 'off_grid');
            }

            if (! $slot->isFree()) {
                throw new BusinessRuleViolation(match ($slot->status) {
                    Slot::BOOKED => 'Horário já ocupado.',
                    Slot::FULL => $slot->reason.'. Use o próximo horário disponível ou um encaixe.',
                    default => 'Horário indisponível: '.($slot->reason ?? $slot->status),
                }, 'slot_'.$slot->status, $slot->status === Slot::BOOKED ? 409 : 422);
            }

            return [$slot->start, $slot->end, $period['template']];
        }

        throw new BusinessRuleViolation('O médico não atende neste dia/horário nesta unidade.', 'no_schedule');
    }

    private function ensurePatientFree(Patient $patient, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreId = null): void
    {
        $conflict = Appointment::query()->active()->where('patient_id', $patient->id)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        if ($conflict) {
            throw new BusinessRuleViolation('O paciente já tem outro agendamento neste horário.', 'patient_conflict');
        }
    }

    /** @return array{0: string, 1: ?string, 2: int} */
    private function pricing(Patient $patient, ?DoctorService $service, string $payer, ?string $insuranceId, string $doctorId, string $date): array
    {
        if ($payer === 'insurance') {
            if ($service && ! $service->accepts_insurance) {
                throw new BusinessRuleViolation('Este atendimento não é realizado por convênio.', 'insurance_not_accepted');
            }

            $insurance = PatientInsurance::query()->where('patient_id', $patient->id)->where('is_active', true)->whereKey($insuranceId)->first();

            if (! $insurance) {
                throw new BusinessRuleViolation('Selecione o convênio do paciente.', 'insurance_required');
            }

            if ($insurance->isExpired()) {
                throw new BusinessRuleViolation('Carteirinha do convênio vencida.', 'insurance_expired');
            }

            // Convênio cadastrado: ativo, médico credenciado e carteirinha válida na data da consulta.
            if ($insurance->insurer_id) {
                $this->insuranceCatalog->assertCoverage($insurance, $doctorId, $date);
            }

            // Valor do convênio vem da tabela do convênio, na guia (Fase 9); o paciente não paga no balcão.
            return ['insurance', $insurance->id, 0];
        }

        if ($service && ! $service->accepts_private) {
            throw new BusinessRuleViolation('Este atendimento não aceita particular.', 'private_not_accepted');
        }

        return ['private', null, $service?->price_cents ?? 0];
    }

    private function lockDoctor(string $doctorId): Doctor
    {
        $doctor = Doctor::query()->whereKey($doctorId)->lockForUpdate()->first();

        if (! $doctor) {
            throw new BusinessRuleViolation('Médico inválido.', 'invalid_doctor');
        }

        return $doctor;
    }

    private function branch(string $branchId): Branch
    {
        $branch = Branch::query()->active()->accessible($this->context->allowedBranchIds())->whereKey($branchId)->first();

        if (! $branch) {
            throw new BusinessRuleViolation('Unidade inválida ou fora do seu escopo.', 'invalid_branch');
        }

        return $branch;
    }

    private function patient(string $patientId): Patient
    {
        $patient = Patient::query()->whereKey($patientId)->first();

        if (! $patient || $patient->status !== 'active' || $patient->isAnonymized()) {
            throw new BusinessRuleViolation('Paciente inválido ou inativo.', 'invalid_patient');
        }

        return $patient;
    }

    private function service(Doctor $doctor, ?string $serviceId): ?DoctorService
    {
        if ($serviceId === null) {
            return null;
        }

        $service = DoctorService::query()->where('doctor_id', $doctor->id)->where('is_active', true)->whereKey($serviceId)->first();

        if (! $service) {
            throw new BusinessRuleViolation('Tipo de atendimento inválido para este médico.', 'invalid_service');
        }

        return $service;
    }

    private function protocol(): string
    {
        $n = $this->sequences->next($this->context->companyId(), 'appointment_protocol');

        return 'AG'.now()->format('y').str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private function idempotentReplay(Appointment $existing, array $data): Appointment
    {
        if ($existing->patient_id !== $data['patient_id'] || $existing->doctor_id !== $data['doctor_id']) {
            throw new BusinessRuleViolation('Chave de idempotência já utilizada em outro agendamento.', 'idempotency_conflict', 409);
        }

        return $existing;
    }
}
