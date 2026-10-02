<?php

namespace App\Modules\Portal\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Portal\Models\PatientAccount;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * O que o paciente vê e faz no portal. TODA consulta é filtrada pelo patient_id da conta
 * logada (além do escopo da clínica). Conteúdo clínico do prontuário não é exibido:
 * apenas datas/profissionais dos atendimentos e os documentos emitidos para o paciente.
 */
class PortalService
{
    /** Tipos de documento entregues ao paciente (o registro de notificação fica com a clínica/farmácia). */
    public const DOCUMENT_TYPES = ['prescription', 'special_prescription', 'certificate', 'exam_request', 'report'];

    public function __construct(
        private readonly BookingService $booking,
        private readonly AppointmentService $appointments,
        private readonly AvailabilityService $availability,
        private readonly AuditLogger $audit,
    ) {}

    public function settings(Company $company): array
    {
        return [
            'booking' => (bool) $company->setting('portal.booking_enabled', true),
            'min_notice_hours' => (int) $company->setting('portal.booking_min_notice_hours', 2),
            'max_days_ahead' => (int) $company->setting('portal.booking_max_days', 60),
            'cancel_hours' => (int) $company->setting('portal.cancel_min_hours', 24),
        ];
    }

    public function appointments(PatientAccount $a): Builder
    {
        return Appointment::query()->with(['doctor:id,name,social_name', 'branch:id,name,street,number,district,city,state,timezone', 'service:id,name'])
            ->where('patient_id', $a->patient_id);
    }

    public function upcoming(PatientAccount $a): Collection
    {
        return $this->appointments($a)->where('starts_at', '>=', now()->subHours(2))->whereIn('status', ['scheduled', 'confirmed', 'arrived', 'in_service'])
            ->orderBy('starts_at')->get();
    }

    public function encounters(PatientAccount $a): Collection
    {
        return Encounter::query()->with(['doctor:id,name,social_name', 'branch:id,name'])
            ->where('patient_id', $a->patient_id)->where('status', 'finalized')->orderByDesc('started_at')->limit(100)
            ->get(['id', 'doctor_id', 'branch_id', 'started_at', 'finalized_at', 'status']);
    }

    public function documents(PatientAccount $a): Builder
    {
        return MedicalDocument::query()->with('doctor:id,name,social_name')
            ->where('patient_id', $a->patient_id)->where('status', 'issued')->whereIn('type', self::DOCUMENT_TYPES);
    }

    public function files(PatientAccount $a): Builder
    {
        return PatientFile::query()->where('patient_id', $a->patient_id)->where('visible_to_patient', true)->where('status', 'active');
    }

    public function receivables(PatientAccount $a): Builder
    {
        return Receivable::query()->with(['transactions' => fn ($q) => $q->where('kind', 'receipt')->whereDoesntHave('reversal')])
            ->where('patient_id', $a->patient_id)->where('status', '!=', 'cancelled');
    }

    /** Médicos que atendem pelo portal: ativos, com tipo de atendimento ativo e grade na unidade. */
    public function bookableDoctors(): Collection
    {
        return Doctor::query()->active()->with(['specialties:id,name', 'branches:id,name', 'services' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->whereHas('services', fn ($q) => $q->where('is_active', true))
            ->whereHas('scheduleTemplates')->orderBy('name')->get();
    }

    /** Próximos horários livres respeitando antecedência mínima e janela máxima da clínica. */
    public function slots(Company $company, Branch $branch, Doctor $doctor, int $limit = 16): array
    {
        $s = $this->settings($company);
        $from = CarbonImmutable::now()->addHours($s['min_notice_hours']);

        return array_values(array_filter(
            $this->availability->next($branch, $doctor, null, $limit, $s['max_days_ahead'], $from),
            fn ($slot) => $slot->start->greaterThanOrEqualTo($from),
        ));
    }

    public function book(Company $company, PatientAccount $a, array $data): Appointment
    {
        $s = $this->settings($company);
        if (! $s['booking']) {
            throw new BusinessRuleViolation('Agendamento pelo portal desativado. Fale com a clínica.', 'portal_booking_disabled', 403);
        }

        $start = CarbonImmutable::parse($data['starts_at']);
        if ($start->lt(CarbonImmutable::now()->addHours($s['min_notice_hours'])) || $start->gt(CarbonImmutable::now()->addDays($s['max_days_ahead']))) {
            throw new BusinessRuleViolation('Horário fora do período permitido para agendamento online.', 'portal_booking_window');
        }

        $service = DoctorService::query()->where('doctor_id', $data['doctor_id'])->where('is_active', true)->find($data['service_id'] ?? null)
            ?? throw new BusinessRuleViolation('Escolha o tipo de atendimento.', 'service_required');

        $appointment = $this->booking->book(null, [
            'doctor_id' => $data['doctor_id'], 'branch_id' => $data['branch_id'], 'patient_id' => $a->patient_id,
            'starts_at' => $start, 'service_id' => $service->id, 'is_overbook' => false, 'channel' => 'portal',
            'payer_type' => $data['payer_type'] ?? 'private', 'patient_insurance_id' => $data['patient_insurance_id'] ?? null,
            'idempotency_key' => isset($data['idempotency_key']) ? 'portal-'.$a->id.'-'.$data['idempotency_key'] : null,
            'notes' => 'Agendado pelo portal do paciente.',
        ]);
        if ($appointment->wasRecentlyCreated) {
            $this->audit->record('portal.appointment_booked', $appointment);
        }

        return $appointment;
    }

    public function cancel(Company $company, PatientAccount $a, Appointment $appointment): Appointment
    {
        $this->assertOwn($a, $appointment);
        $hours = $this->settings($company)['cancel_hours'];

        if (! in_array($appointment->status, ['scheduled', 'confirmed'], true)) {
            throw new BusinessRuleViolation('Este agendamento não pode mais ser cancelado pelo portal.', 'portal_cancel_not_allowed', 409);
        }
        if ($appointment->starts_at->lt(now()->addHours($hours))) {
            throw new BusinessRuleViolation("Cancelamento pelo portal só até {$hours} horas antes. Fale com a clínica.", 'portal_cancel_too_late');
        }

        return $this->appointments->cancel(null, $appointment, 'Cancelado pelo paciente no portal', notify: false);
    }

    public function confirm(PatientAccount $a, Appointment $appointment): Appointment
    {
        $this->assertOwn($a, $appointment);
        if ($appointment->status !== 'scheduled' || $appointment->starts_at->isPast()) {
            throw new BusinessRuleViolation('Este agendamento não pode ser confirmado.', 'portal_confirm_not_allowed', 409);
        }

        return $this->appointments->confirm(null, $appointment, 'portal');
    }

    public function assertOwn(PatientAccount $a, object $model): void
    {
        if (($model->patient_id ?? null) !== $a->patient_id) {
            abort(404);
        }
    }
}
