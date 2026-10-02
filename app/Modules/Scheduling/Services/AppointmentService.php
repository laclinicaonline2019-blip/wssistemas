<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Identity\Models\User;
use App\Modules\Insurance\Services\GuideService;
use App\Modules\Queue\Models\QueueTicket;
use App\Modules\Queue\Services\QueueService;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Facades\DB;

/** Ciclo de vida do agendamento (máquina de estados + auditoria). */
class AppointmentService
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly AuditLogger $audit,
        private readonly FinanceService $finance,
        private readonly GuideService $guides,
    ) {}

    public function confirm(User $actor, Appointment $appointment, string $channel = 'reception'): Appointment
    {
        $this->transition($appointment, 'confirmed', ['confirmed_at' => now()], ['channel' => $channel]);

        return $appointment;
    }

    public function cancel(User $actor, Appointment $appointment, string $reason): Appointment
    {
        $this->transition($appointment, 'cancelled', [
            'cancelled_at' => now(), 'cancel_reason' => $reason, 'cancelled_by' => $actor->id,
        ], ['reason' => $reason]);
        $this->finance->cancelAppointmentReceivable($appointment, $actor, 'Agendamento cancelado: '.$reason);

        // Senha aberta vinculada deixa de valer.
        QueueTicket::query()->where('appointment_id', $appointment->id)->whereIn('status', QueueTicket::OPEN)
            ->each(fn (QueueTicket $t) => $t->update(['status' => 'cancelled']));

        return $appointment;
    }

    public function noShow(User $actor, Appointment $appointment): Appointment
    {
        if ($appointment->starts_at->isFuture()) {
            throw new BusinessRuleViolation('Só é possível registrar falta após o horário marcado.', 'too_early');
        }

        $this->transition($appointment, 'no_show');
        $this->finance->cancelAppointmentReceivable($appointment, $actor, 'Paciente faltou ao agendamento');

        return $appointment;
    }

    /** Chegada do paciente: registra e gera a senha da fila. */
    public function arrive(User $actor, Appointment $appointment, ?string $ticketType = null): QueueTicket
    {
        return DB::transaction(function () use ($actor, $appointment, $ticketType) {
            $this->transition($appointment, 'arrived', ['arrived_at' => now()]);
            // Particular com valor: gera a conta a receber para o caixa cobrar.
            $this->finance->receivableForAppointment($appointment, $actor);
            // Convênio: abre a guia do atendimento. Pendência de cadastro (convênio não cadastrado,
            // médico não credenciado…) não impede a chegada — a guia é gerada depois, na tela do agendamento.
            try {
                $this->guides->forAppointment($appointment, $actor);
            } catch (BusinessRuleViolation) {
            }

            return $this->queue->issue($actor, [
                'branch_id' => $appointment->branch_id,
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'room_id' => $appointment->room_id,
                'type' => $ticketType ?? $this->queue->suggestType($appointment),
            ]);
        });
    }

    public function start(Appointment $appointment): Appointment
    {
        $this->transition($appointment, 'in_service', ['started_at' => now()]);

        return $appointment;
    }

    public function complete(Appointment $appointment): Appointment
    {
        $this->transition($appointment, 'completed', ['completed_at' => now()]);

        return $appointment;
    }

    private function transition(Appointment $appointment, string $to, array $extra = [], array $metadata = []): void
    {
        if (! $appointment->canTransitionTo($to)) {
            throw new BusinessRuleViolation(
                sprintf('Não é possível mudar de "%s" para "%s".', $appointment->statusLabel(), Appointment::STATUSES[$to] ?? $to),
                'invalid_transition',
            );
        }

        $from = $appointment->status;
        Appointment::withoutAuditing(fn () => $appointment->forceFill(['status' => $to, ...$extra])->save());
        $this->audit->record('appointment.'.$to, $appointment, old: ['status' => $from], new: ['status' => $to], metadata: $metadata);
    }
}
