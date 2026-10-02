<?php

namespace App\Modules\Ai\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Models\AiConfig;
use App\Modules\Ai\Models\AiSession;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\Phone;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\DuplicatePatientCandidates;
use App\Modules\Patients\Services\PatientService;
use App\Modules\Payments\Models\PaymentGateway;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Platform\Models\Company;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Modules\Scheduling\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ferramentas da recepcionista virtual. O modelo só PEDE; tudo é executado aqui, com as
 * mesmas regras da agenda (disponibilidade real, sem encaixe, sem dupla marcação),
 * restrito à clínica e ao paciente identificado na conversa.
 *
 * Proteções:
 * - dados de consultas só para o paciente identificado (telefone único ou CPF + nascimento);
 * - agendar exige DUAS etapas: propor (resumo lido para o paciente) e confirmar numa mensagem
 *   POSTERIOR do paciente — a IA não marca nada na mesma resposta em que propôs;
 * - 3 tentativas de identificação erradas → conversa vai para a equipe.
 */
class ReceptionistTools
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingService $booking,
        private readonly AppointmentService $appointments,
        private readonly PatientService $patients,
        private readonly FinanceService $finance,
        private readonly PaymentService $payments,
        private readonly HandoffService $handoff,
    ) {}

    /** @return list<array{name: string, description: string, schema: array}> */
    public function definitions(AiConfig $config): array
    {
        $obj = fn (array $props = [], array $required = []) => ['type' => 'object', 'properties' => (object) $props, 'required' => $required, 'additionalProperties' => false];
        $tools = [
            ['name' => 'list_specialties', 'description' => 'Lista as especialidades atendidas na clínica.', 'schema' => $obj()],
            ['name' => 'list_doctors', 'description' => 'Lista os médicos (opcionalmente de uma especialidade), com unidades e tipos de atendimento (valor particular e se aceita convênio).',
                'schema' => $obj(['specialty_id' => ['type' => 'string', 'description' => 'ID da especialidade (opcional)']])],
            ['name' => 'find_available_slots', 'description' => 'Busca os próximos horários LIVRES reais de um médico ou de uma especialidade. Nunca ofereça horário que não veio desta ferramenta.',
                'schema' => $obj([
                    'doctor_id' => ['type' => 'string'], 'specialty_id' => ['type' => 'string'], 'branch_id' => ['type' => 'string'],
                    'from_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD (opcional)'],
                ])],
            ['name' => 'identify_patient', 'description' => 'Confirma a identidade do paciente pelo CPF e data de nascimento (obrigatório antes de mostrar/alterar consultas quando o telefone não identificou o paciente).',
                'schema' => $obj(['cpf' => ['type' => 'string'], 'birth_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD']], ['cpf', 'birth_date'])],
            ['name' => 'register_patient', 'description' => 'Cadastra um paciente NOVO (nome completo, CPF e data de nascimento informados pelo próprio paciente). Use só se identify_patient não encontrou cadastro.',
                'schema' => $obj(['name' => ['type' => 'string'], 'cpf' => ['type' => 'string'], 'birth_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'email' => ['type' => 'string']], ['name', 'cpf', 'birth_date'])],
            ['name' => 'my_appointments', 'description' => 'Lista as próximas consultas do paciente identificado.', 'schema' => $obj()],
            ['name' => 'handoff_to_human', 'description' => 'Passa a conversa para a equipe da clínica (pedido do paciente, dúvida clínica, reclamação, assunto fora do seu escopo ou quando não tiver certeza).',
                'schema' => $obj(['reason' => ['type' => 'string']], ['reason'])],
        ];

        if ($config->setting('allow_booking', true)) {
            $tools[] = ['name' => 'propose_appointment', 'description' => 'Etapa 1 de 2 do agendamento: registra a proposta (horário vindo de find_available_slots) e devolve o resumo para você LER ao paciente e pedir confirmação. Não marca nada.',
                'schema' => $obj([
                    'slot_id' => ['type' => 'string'], 'service_id' => ['type' => 'string', 'description' => 'Tipo de atendimento (de list_doctors)'],
                    'payer_type' => ['type' => 'string', 'enum' => ['private', 'insurance']], 'insurance_id' => ['type' => 'string', 'description' => 'Carteirinha (se convênio)'],
                ], ['slot_id', 'payer_type'])];
            $tools[] = ['name' => 'confirm_appointment', 'description' => 'Etapa 2 de 2: marca a consulta proposta. Só use depois que o paciente respondeu CONFIRMANDO o resumo, numa mensagem nova.', 'schema' => $obj()];
        }
        if ($config->setting('allow_cancel', true)) {
            $tools[] = ['name' => 'cancel_appointment', 'description' => 'Cancela uma consulta do paciente identificado, depois que ele confirmou que quer cancelar.',
                'schema' => $obj(['appointment_id' => ['type' => 'string']], ['appointment_id'])];
        }

        return $tools;
    }

    public function execute(AiSession $session, MessageThread $thread, string $name, array $input): array
    {
        try {
            return match ($name) {
                'list_specialties' => $this->specialties(),
                'list_doctors' => $this->doctors($input['specialty_id'] ?? null),
                'find_available_slots' => $this->slots($input),
                'identify_patient' => $this->identify($session, $thread, (string) ($input['cpf'] ?? ''), (string) ($input['birth_date'] ?? '')),
                'register_patient' => $this->register($session, $thread, $input),
                'my_appointments' => $this->myAppointments($session),
                'propose_appointment' => $this->propose($session, $thread, $input),
                'confirm_appointment' => $this->confirm($session, $thread),
                'cancel_appointment' => $this->cancel($session, (string) ($input['appointment_id'] ?? '')),
                'handoff_to_human' => $this->handoff->handoff($session, $thread, mb_substr((string) ($input['reason'] ?? 'Pedido de atendimento humano'), 0, 200)),
                default => ['error' => 'Ferramenta desconhecida.'],
            };
        } catch (BusinessRuleViolation $e) {
            return ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Falha interna ao executar a ação. Ofereça falar com a equipe.'];
        }
    }

    private function specialties(): array
    {
        return ['specialties' => Specialty::query()->where('is_active', true)->whereHas('doctors', fn ($q) => $q->where('status', 'active'))->orderBy('name')->get(['id', 'name'])->toArray()];
    }

    private function doctors(?string $specialtyId): array
    {
        return ['doctors' => Doctor::query()->active()->with(['specialties:id,name', 'branches:id,name', 'services' => fn ($q) => $q->where('is_active', true)])
            ->when($specialtyId, fn ($q) => $q->whereHas('specialties', fn ($s) => $s->where('specialties.id', $specialtyId)))
            ->orderBy('name')->limit(30)->get()->map(fn (Doctor $d) => [
                'id' => $d->id, 'name' => $d->displayName(), 'specialties' => $d->specialties->pluck('name')->all(),
                'branches' => $d->branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all(),
                'services' => $d->services->map(fn (DoctorService $s) => ['id' => $s->id, 'name' => $s->name,
                    'private_price' => $s->accepts_private ? Format::money($s->price_cents) : null, 'accepts_insurance' => $s->accepts_insurance])->all(),
            ])->all()];
    }

    private function slots(array $input): array
    {
        $company = Company::query()->findOrFail($this->companyId());
        $minNotice = (int) $company->setting('portal.booking_min_notice_hours', 2);
        $from = CarbonImmutable::now()->addHours($minNotice);
        if (! empty($input['from_date']) && ($d = CarbonImmutable::createFromFormat('Y-m-d', $input['from_date'], 'America/Sao_Paulo')) && $d->startOfDay()->gt($from)) {
            $from = $d->startOfDay()->utc();
        }

        $doctor = ! empty($input['doctor_id']) ? Doctor::query()->active()->find($input['doctor_id']) : null;
        $branches = Branch::query()->active()->when(! empty($input['branch_id']), fn ($q) => $q->whereKey($input['branch_id']))
            ->when($doctor, fn ($q) => $q->whereIn('id', $doctor->branches()->pluck('branches.id')))->get();

        $found = [];
        foreach ($branches as $branch) {
            foreach ($this->availability->next($branch, $doctor, $input['specialty_id'] ?? null, 6, 45, $from) as $slot) {
                if ($slot->start->lt($from)) {
                    continue;
                }
                $local = $slot->start->setTimezone($branch->timezone ?: 'America/Sao_Paulo');
                $found[] = [
                    'slot_id' => $slot->doctorId.'|'.$branch->id.'|'.$slot->start->utc()->format('Y-m-d\TH:i'),
                    'when' => $local->locale('pt_BR')->translatedFormat('l, d/m/Y \à\s H:i'), 'doctor' => Doctor::query()->find($slot->doctorId)?->displayName(), 'branch' => $branch->name,
                ];
            }
        }
        usort($found, fn ($a, $b) => strcmp(explode('|', $a['slot_id'])[2], explode('|', $b['slot_id'])[2]));

        return $found === [] ? ['slots' => [], 'note' => 'Sem horários livres no período. Ofereça outra data, outro médico ou falar com a equipe.'] : ['slots' => array_slice($found, 0, 8)];
    }

    private function identify(AiSession $session, MessageThread $thread, string $cpf, string $birth): array
    {
        if ($session->patient_id) {
            return ['identified' => true, 'patient' => $this->patientSummary(Patient::query()->find($session->patient_id))];
        }

        $attempts = (int) $session->stateValue('id_attempts', 0);
        if ($attempts >= 3) {
            return $this->handoff->handoff($session, $thread, 'Identificação do paciente não confirmada após 3 tentativas');
        }

        $digits = Format::digits($cpf);
        $patient = $digits && strlen($digits) === 11 ? Patient::query()->where('cpf', $digits)->whereNull('anonymized_at')->first() : null;
        if (! $patient || $patient->birth_date?->toDateString() !== substr($birth, 0, 10)) {
            $session->putState('id_attempts', $attempts + 1);
            $session->save();

            return ['identified' => false, 'note' => 'CPF e data de nascimento não conferem com um cadastro. Não revele se o CPF existe. Peça para conferir ou ofereça cadastro novo.'];
        }

        $this->link($session, $thread, $patient);

        return ['identified' => true, 'patient' => $this->patientSummary($patient)];
    }

    private function register(AiSession $session, MessageThread $thread, array $input): array
    {
        if ($session->patient_id) {
            return ['error' => 'O paciente já está identificado nesta conversa.'];
        }
        $cpf = Format::digits((string) ($input['cpf'] ?? ''));
        if ($cpf && Patient::query()->where('cpf', $cpf)->exists()) {
            return ['error' => 'Já existe cadastro com este CPF. Use identify_patient com a data de nascimento.'];
        }

        try {
            $patient = $this->patients->create(null, [
                'name' => mb_substr(trim((string) $input['name']), 0, 150), 'cpf' => $cpf, 'birth_date' => substr((string) $input['birth_date'], 0, 10),
                'whatsapp' => substr((string) Phone::e164($thread->phone), 2) ?: null, 'email' => $input['email'] ?? null,
                'home_branch_id' => Branch::query()->active()->orderByDesc('is_headquarters')->value('id'),
            ]);
        } catch (DuplicatePatientCandidates) {
            return $this->handoff->handoff($session, $thread, 'Possível cadastro duplicado ao registrar paciente pelo WhatsApp');
        } catch (ValidationException $e) {
            return ['error' => 'Dados inválidos: '.implode(' ', $e->validator->errors()->all())];
        }

        $this->link($session, $thread, $patient);

        return ['registered' => true, 'patient' => $this->patientSummary($patient)];
    }

    private function myAppointments(AiSession $session): array
    {
        $patient = $this->requirePatient($session);

        return ['appointments' => Appointment::query()->with(['doctor:id,name,social_name', 'branch:id,name,timezone'])
            ->where('patient_id', $patient->id)->whereIn('status', ['scheduled', 'confirmed'])->where('starts_at', '>', now())
            ->orderBy('starts_at')->limit(10)->get()->map(fn (Appointment $a) => [
                'appointment_id' => $a->id, 'when' => $a->starts_at->timezone($a->branch->timezone ?: 'America/Sao_Paulo')->locale('pt_BR')->translatedFormat('l, d/m/Y \à\s H:i'),
                'doctor' => $a->doctor->displayName(), 'branch' => $a->branch->name, 'status' => $a->statusLabel(), 'protocol' => $a->protocol,
            ])->all()];
    }

    private function propose(AiSession $session, MessageThread $thread, array $input): array
    {
        $patient = $this->requirePatient($session);
        [$doctorId, $branchId, $start] = array_pad(explode('|', (string) $input['slot_id']), 3, null);
        $doctor = $doctorId ? Doctor::query()->active()->find($doctorId) : null;
        $branch = $branchId ? Branch::query()->active()->find($branchId) : null;
        if (! $doctor || ! $branch || ! $start) {
            return ['error' => 'Horário inválido. Use um slot_id de find_available_slots.'];
        }

        $service = ! empty($input['service_id'])
            ? DoctorService::query()->where('doctor_id', $doctor->id)->where('is_active', true)->find($input['service_id'])
            : DoctorService::query()->where('doctor_id', $doctor->id)->where('is_active', true)->where('is_return', false)->orderBy('name')->first();
        if (! $service) {
            return ['error' => 'Tipo de atendimento inválido para este médico.'];
        }

        $payer = $input['payer_type'] === 'insurance' ? 'insurance' : 'private';
        $insurance = null;
        if ($payer === 'insurance') {
            $insurance = $patient->insurances()->whereNotNull('insurer_id')->when(! empty($input['insurance_id']), fn ($q) => $q->whereKey($input['insurance_id']))->first();
            if (! $insurance) {
                return ['error' => 'O paciente não tem carteirinha de convênio cadastrada. Ofereça particular ou falar com a equipe.'];
            }
        }

        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $start, 'UTC');
        $last = Message::query()->where('thread_id', $thread->id)->where('direction', 'in')->latest('created_at')->latest('id')->value('id');
        $session->putState('draft', [
            'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'starts_at' => $startsAt->toIso8601String(), 'service_id' => $service->id,
            'payer_type' => $payer, 'patient_insurance_id' => $insurance?->id, 'after_message' => $last, 'at' => now()->toIso8601String(),
        ]);
        $session->save();

        return ['proposal' => [
            'patient' => $patient->displayName(), 'doctor' => $doctor->displayName(), 'service' => $service->name, 'branch' => $branch->name,
            'address' => $branch->fullAddress(), 'when' => $startsAt->setTimezone($branch->timezone ?: 'America/Sao_Paulo')->locale('pt_BR')->translatedFormat('l, d/m/Y \à\s H:i'),
            'payment' => $payer === 'insurance' ? 'Convênio '.$insurance->insurer_name : 'Particular '.Format::money($service->price_cents),
        ], 'next' => 'Leia este resumo ao paciente e pergunte se confirma. Só chame confirm_appointment depois da resposta dele.'];
    }

    private function confirm(AiSession $session, MessageThread $thread): array
    {
        $patient = $this->requirePatient($session);
        $draft = $session->stateValue('draft');
        if (! $draft) {
            return ['error' => 'Não há proposta de agendamento. Use propose_appointment antes.'];
        }

        // Proteção: a confirmação precisa vir numa mensagem do paciente POSTERIOR à proposta.
        $answered = Message::query()->where('thread_id', $thread->id)->where('direction', 'in')
            ->where('created_at', '>=', $draft['at'])->when($draft['after_message'], fn ($q, $id) => $q->where('id', '!=', $id))->exists();
        if (! $answered) {
            return ['error' => 'Aguarde a resposta do paciente confirmando o resumo antes de marcar.'];
        }

        $appointment = $this->booking->book(null, [
            'doctor_id' => $draft['doctor_id'], 'branch_id' => $draft['branch_id'], 'patient_id' => $patient->id, 'starts_at' => CarbonImmutable::parse($draft['starts_at']),
            'service_id' => $draft['service_id'], 'payer_type' => $draft['payer_type'], 'patient_insurance_id' => $draft['patient_insurance_id'],
            'is_overbook' => false, 'channel' => 'ai', 'idempotency_key' => 'ai-'.$session->id.'-'.md5($draft['starts_at'].$draft['doctor_id']),
            'notes' => 'Agendado pela assistente virtual (WhatsApp).',
        ]);
        $session->putState('draft', null);
        $session->save();

        $result = ['booked' => true, 'protocol' => $appointment->protocol, 'when' => $appointment->starts_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i')];

        // Pré-pagamento online (opcional): cobrança PIX/cartão pelo gateway da clínica.
        $config = AiConfig::query()->first();
        if ($config?->setting('prepayment', false) && $appointment->payer_type === 'private' && $appointment->price_cents > 0
            && ($gateway = PaymentGateway::query()->where('is_active', true)->orderByDesc('is_default')->first())) {
            $receivable = $this->finance->receivableForAppointment($appointment);
            $charge = $this->payments->createCharge(null, $receivable, $gateway, $gateway->provider === 'cielo_api' ? 'credit_card' : 'pix',
                $receivable->balanceCents(), $appointment->starts_at->timezone('America/Sao_Paulo')->toDateString(), 'ai-'.$appointment->id);
            $result['payment_link'] = route('payments.public', $charge->public_token);
            $result['amount'] = Format::money($charge->amount_cents);
        }

        return $result;
    }

    private function cancel(AiSession $session, string $appointmentId): array
    {
        $patient = $this->requirePatient($session);
        $a = Appointment::query()->where('patient_id', $patient->id)->find($appointmentId);
        if (! $a || ! in_array($a->status, ['scheduled', 'confirmed'], true)) {
            return ['error' => 'Consulta não encontrada entre as consultas ativas do paciente.'];
        }
        $hours = (int) Company::query()->findOrFail($this->companyId())->setting('messaging.cancel_min_hours', 2);
        if ($a->starts_at->lt(now()->addHours($hours))) {
            return ['error' => "Cancelamento automático só até {$hours} h antes. Ofereça falar com a equipe."];
        }

        $this->appointments->cancel(null, $a, 'Cancelado pelo paciente com a assistente virtual', notify: false);

        return ['cancelled' => true, 'protocol' => $a->protocol];
    }

    private function requirePatient(AiSession $session): Patient
    {
        $patient = $session->patient_id ? Patient::query()->find($session->patient_id) : null;
        if (! $patient) {
            throw new BusinessRuleViolation('Paciente não identificado: peça CPF e data de nascimento (identify_patient) ou faça o cadastro (register_patient).', 'ai_patient_required');
        }

        return $patient;
    }

    private function link(AiSession $session, MessageThread $thread, Patient $patient): void
    {
        $session->forceFill(['patient_id' => $patient->id])->save();
        if (! $thread->patient_id) {
            $thread->forceFill(['patient_id' => $patient->id])->save();
        }
    }

    private function patientSummary(?Patient $p): ?array
    {
        return $p ? [
            'first_name' => strtok((string) ($p->social_name ?: $p->name), ' '),
            'insurances' => $p->insurances()->whereNotNull('insurer_id')->get()->map(fn ($i) => ['insurance_id' => $i->id, 'insurer' => $i->insurer_name])->all(),
        ] : null;
    }

    private function companyId(): string
    {
        return app(TenantContext::class)->companyId();
    }
}
