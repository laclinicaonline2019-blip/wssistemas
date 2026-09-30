<?php

namespace App\Modules\Queue\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\SequenceGenerator;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queue\Models\QueueCall;
use App\Modules\Queue\Models\QueueTicket;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Room;
use App\Modules\Scheduling\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fila de atendimento: emissão de senhas, chamadas e painel. */
class QueueService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SequenceGenerator $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, array{prefix: string, label: string, priority: bool}> */
    public function types(): array
    {
        return config('aivexa.queue_types');
    }

    /**
     * Emite uma senha. Numeração por unidade + dia (fuso da unidade) + tipo, sem colisão em concorrência.
     *
     * @param  array{branch_id: string, type: string, appointment_id?: ?string, patient_id?: ?string, doctor_id?: ?string, room_id?: ?string, notes?: ?string}  $data
     */
    public function issue(User $actor, array $data): QueueTicket
    {
        $type = $this->types()[$data['type']] ?? throw new BusinessRuleViolation('Tipo de senha inválido.', 'invalid_ticket_type');
        $branch = $this->branch($data['branch_id']);

        if (! empty($data['patient_id']) && ! Patient::query()->whereKey($data['patient_id'])->exists()) {
            throw new BusinessRuleViolation('Paciente inválido.', 'invalid_patient');
        }

        return DB::transaction(function () use ($actor, $data, $type, $branch) {
            $today = CarbonImmutable::now($branch->timezone)->toDateString();
            $number = $this->sequences->next($this->context->companyId(), "ticket:{$branch->id}:{$today}:{$type['prefix']}");

            return QueueTicket::create([
                'branch_id' => $branch->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'patient_id' => $data['patient_id'] ?? null,
                'doctor_id' => $data['doctor_id'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'service_date' => $today,
                'type' => $data['type'],
                'prefix' => $type['prefix'],
                'number' => $number,
                'code' => $type['prefix'].str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'is_priority' => $type['priority'],
                'status' => 'waiting',
                'arrived_at' => now(),
                'created_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /** Tipo sugerido na chegada: prioridade (60+), retorno, convênio ou geral. */
    public function suggestType(Appointment $appointment): string
    {
        $age = $appointment->patient?->age();

        return match (true) {
            $age !== null && $age >= 60 => 'prioridade',
            (bool) $appointment->service?->is_return => 'retorno',
            $appointment->payer_type === 'insurance' => 'convenio',
            default => 'geral',
        };
    }

    /** Chama uma senha (ou a próxima: prioridades primeiro, depois ordem de chegada). */
    public function call(User $actor, string $branchId, ?QueueTicket $ticket = null, ?string $roomId = null): QueueTicket
    {
        $branch = $this->branch($branchId);

        return DB::transaction(function () use ($actor, $branch, $ticket, $roomId) {
            $ticket ??= QueueTicket::query()
                ->where('branch_id', $branch->id)->where('service_date', CarbonImmutable::now($branch->timezone)->toDateString())
                ->where('status', 'waiting')
                ->orderByDesc('is_priority')->orderBy('arrived_at')->orderBy('number')
                ->lockForUpdate()->first();

            if (! $ticket) {
                throw new BusinessRuleViolation('Não há senhas aguardando.', 'queue_empty');
            }

            if (! in_array($ticket->status, ['waiting', 'called'], true) || $ticket->branch_id !== $branch->id) {
                throw new BusinessRuleViolation('Esta senha não pode ser chamada agora.', 'invalid_ticket_status');
            }

            if ($roomId !== null) {
                $room = Room::query()->where('branch_id', $branch->id)->whereKey($roomId)->first()
                    ?? throw new BusinessRuleViolation('Sala inválida.', 'invalid_room');
                $ticket->room_id = $room->id;
            }

            $ticket->forceFill(['status' => 'called', 'called_at' => now(), 'call_count' => $ticket->call_count + 1])->save();
            $this->registerCall($actor, $ticket->fresh(['patient', 'room', 'doctor']), $branch);

            return $ticket;
        });
    }

    public function recall(User $actor, QueueTicket $ticket): QueueTicket
    {
        if ($ticket->status !== 'called') {
            throw new BusinessRuleViolation('Só é possível rechamar uma senha já chamada.', 'invalid_ticket_status');
        }

        return $this->call($actor, $ticket->branch_id, $ticket);
    }

    public function start(QueueTicket $ticket): QueueTicket
    {
        $this->move($ticket, ['called', 'waiting'], 'in_service', ['started_at' => now()]);

        if ($ticket->appointment && $ticket->appointment->canTransitionTo('in_service')) {
            app(AppointmentService::class)->start($ticket->appointment);
        }

        return $ticket;
    }

    public function finish(QueueTicket $ticket): QueueTicket
    {
        $this->move($ticket, ['in_service', 'called'], 'done', ['finished_at' => now()]);

        if ($ticket->appointment && $ticket->appointment->canTransitionTo('completed')) {
            app(AppointmentService::class)->complete($ticket->appointment);
        }

        return $ticket;
    }

    public function skip(QueueTicket $ticket): QueueTicket
    {
        $this->move($ticket, ['waiting', 'called'], 'skipped', ['finished_at' => now()]);

        return $ticket;
    }

    /** Encaminha/transfere para outra sala/médico: volta para "aguardando" mantendo a ordem de chegada. */
    public function transfer(QueueTicket $ticket, ?string $roomId, ?string $doctorId): QueueTicket
    {
        if (! $ticket->isOpen()) {
            throw new BusinessRuleViolation('Senha já encerrada.', 'invalid_ticket_status');
        }

        if ($roomId && ! Room::query()->where('branch_id', $ticket->branch_id)->whereKey($roomId)->exists()) {
            throw new BusinessRuleViolation('Sala inválida.', 'invalid_room');
        }

        $old = $ticket->only(['room_id', 'doctor_id', 'status']);
        QueueTicket::withoutAuditing(fn () => $ticket->forceFill(['room_id' => $roomId, 'doctor_id' => $doctorId ?? $ticket->doctor_id, 'status' => 'waiting'])->save());
        $this->audit->record('queue_ticket.transferred', $ticket, old: $old, new: $ticket->only(['room_id', 'doctor_id', 'status']));

        return $ticket;
    }

    /** Fila do dia da unidade. */
    public function today(string $branchId): Collection
    {
        $branch = $this->branch($branchId);

        return QueueTicket::query()->with(['patient:id,name,social_name,birth_date', 'room:id,name,number', 'doctor:id,name,social_name', 'appointment:id,starts_at,protocol'])
            ->where('branch_id', $branch->id)->where('service_date', CarbonImmutable::now($branch->timezone)->toDateString())
            ->orderByRaw("CASE status WHEN 'called' THEN 0 WHEN 'in_service' THEN 1 WHEN 'waiting' THEN 2 ELSE 3 END")
            ->orderByDesc('is_priority')->orderBy('arrived_at')
            ->get();
    }

    /** Estado do painel da TV: chamada atual + últimas chamadas (somente dados mínimos). */
    public function panelState(Branch $branch, int $history = 5): array
    {
        $calls = QueueCall::query()->where('branch_id', $branch->id)
            ->where('called_at', '>=', CarbonImmutable::now($branch->timezone)->startOfDay()->utc())
            ->orderByDesc('id')->limit($history + 1)->get();

        $map = fn (QueueCall $c) => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->display_name, 'room' => $c->room_label,
            'doctor' => $c->doctor_label, 'time' => $c->called_at->setTimezone($branch->timezone)->format('H:i'),
        ];

        return [
            'branch' => $branch->name,
            'current' => $calls->first() ? $map($calls->first()) : null,
            'previous' => $calls->slice(1)->map($map)->values()->all(),
            'server_time' => CarbonImmutable::now($branch->timezone)->format('H:i'),
            'settings' => [
                'sound' => (bool) data_get($branch->settings, 'panel.sound', true),
                'voice' => (bool) data_get($branch->settings, 'panel.voice', true),
                'repeat' => (int) data_get($branch->settings, 'panel.repeat', 2),
                'volume' => (float) data_get($branch->settings, 'panel.volume', 1.0),
            ],
        ];
    }

    /** Nome exibido no painel conforme configuração da unidade (LGPD: minimização). */
    public function displayName(?Patient $patient, Branch $branch): ?string
    {
        if (! $patient || data_get($branch->settings, 'panel.show_name', 'short') === 'none') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($patient->displayName()));
        $first = Str::title(Str::lower($parts[0] ?? ''));
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : '';

        return trim($first.' '.$last);
    }

    private function registerCall(User $actor, QueueTicket $ticket, Branch $branch): void
    {
        QueueCall::create([
            'branch_id' => $branch->id,
            'ticket_id' => $ticket->id,
            'code' => $ticket->code,
            'display_name' => $this->displayName($ticket->patient, $branch),
            'room_label' => $ticket->room?->label(),
            'doctor_label' => $ticket->doctor?->displayName(),
            'called_by' => $actor->id,
            'called_at' => now(),
        ]);
    }

    private function move(QueueTicket $ticket, array $from, string $to, array $extra = []): void
    {
        if (! in_array($ticket->status, $from, true)) {
            throw new BusinessRuleViolation('Ação não permitida para a situação atual da senha.', 'invalid_ticket_status');
        }

        $ticket->forceFill(['status' => $to, ...$extra])->save();
    }

    private function branch(string $branchId): Branch
    {
        return Branch::query()->accessible($this->context->allowedBranchIds())->whereKey($branchId)->first()
            ?? throw new BusinessRuleViolation('Unidade inválida ou fora do seu escopo.', 'invalid_branch');
    }
}
