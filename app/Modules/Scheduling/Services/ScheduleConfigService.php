<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\DoctorService;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\Room;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Configuração da agenda: grades, serviços, bloqueios, feriados e salas. */
class ScheduleConfigService
{
    public function __construct(private readonly TenantContext $context) {}

    public function saveTemplate(Doctor $doctor, array $data, ?ScheduleTemplate $template = null): ScheduleTemplate
    {
        $branch = $this->branch($data['branch_id'] ?? $template?->branch_id);

        if (! $doctor->branches()->where('branches.id', $branch->id)->exists()) {
            throw new BusinessRuleViolation('O médico não atende nesta unidade. Vincule a unidade no cadastro do médico.', 'doctor_not_in_branch');
        }

        if (! empty($data['room_id']) && ! Room::query()->where('branch_id', $branch->id)->whereKey($data['room_id'])->exists()) {
            throw new BusinessRuleViolation('Sala inválida para esta unidade.', 'invalid_room');
        }

        $attrs = array_merge($template?->only(['weekday', 'start_time', 'end_time', 'valid_from', 'valid_until']) ?? [], $data);
        $start = substr($attrs['start_time'], 0, 5);
        $end = substr($attrs['end_time'], 0, 5);

        // Um médico não pode estar em dois períodos sobrepostos (em qualquer unidade).
        $overlap = ScheduleTemplate::query()->active()
            ->where('doctor_id', $doctor->id)->where('weekday', $attrs['weekday'])
            ->when($template, fn ($q) => $q->whereKeyNot($template->id))
            ->get()
            ->first(fn (ScheduleTemplate $t) => substr($t->start_time, 0, 5) < $end && substr($t->end_time, 0, 5) > $start
                && $this->validityOverlaps($t, $attrs['valid_from'] ?? null, $attrs['valid_until'] ?? null));

        if ($overlap) {
            throw new BusinessRuleViolation("Conflito com o período {$overlap->label()} ({$overlap->branch?->name}).", 'template_overlap');
        }

        $template ??= new ScheduleTemplate(['doctor_id' => $doctor->id]);
        $template->fill($data + ['branch_id' => $branch->id])->save();

        return $template;
    }

    public function saveService(Doctor $doctor, array $data, ?DoctorService $service = null): DoctorService
    {
        $service ??= new DoctorService(['doctor_id' => $doctor->id]);
        $service->fill($data)->save();

        return $service;
    }

    /**
     * Cria bloqueio. Não cancela agendamentos automaticamente: devolve os afetados
     * para a recepção remarcar/avisar os pacientes.
     *
     * @return array{block: ScheduleBlock, affected: Collection}
     */
    public function createBlock(User $actor, array $data): array
    {
        $branch = ! empty($data['branch_id']) ? $this->branch($data['branch_id']) : null;
        $tz = $branch?->timezone ?? 'America/Sao_Paulo';
        $start = CarbonImmutable::parse($data['starts_at'], $tz)->utc();
        $end = CarbonImmutable::parse($data['ends_at'], $tz)->utc();

        if ($end <= $start) {
            throw new BusinessRuleViolation('O fim do bloqueio deve ser posterior ao início.', 'invalid_period');
        }

        if ($branch === null && $this->context->allowedBranchIds() !== null) {
            throw new BusinessRuleViolation('Informe a unidade do bloqueio.', 'branch_required');
        }

        $block = ScheduleBlock::create([
            'doctor_id' => $data['doctor_id'] ?? null, 'branch_id' => $branch?->id, 'starts_at' => $start, 'ends_at' => $end,
            'type' => $data['type'] ?? 'block', 'reason' => $data['reason'], 'created_by' => $actor->id,
        ]);

        $affected = Appointment::query()->active()->whereIn('status', ['scheduled', 'confirmed'])
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($block->doctor_id, fn ($q) => $q->where('doctor_id', $block->doctor_id))
            ->when($block->branch_id, fn ($q) => $q->where('branch_id', $block->branch_id))
            ->with(['patient:id,name,social_name,whatsapp,phone', 'doctor:id,name,social_name'])
            ->orderBy('starts_at')->get();

        return ['block' => $block, 'affected' => $affected];
    }

    public function saveHoliday(array $data): Holiday
    {
        if (! empty($data['branch_id'])) {
            $this->branch($data['branch_id']);
        } elseif ($this->context->allowedBranchIds() !== null) {
            throw new BusinessRuleViolation('Informe a unidade do feriado.', 'branch_required');
        }

        return Holiday::create($data);
    }

    public function saveRoom(array $data, ?Room $room = null): Room
    {
        $this->branch($data['branch_id'] ?? $room?->branch_id);
        $room ??= new Room;
        $room->fill($data)->save();

        return $room;
    }

    private function branch(?string $id): Branch
    {
        return Branch::query()->accessible($this->context->allowedBranchIds())->whereKey($id)->first()
            ?? throw new BusinessRuleViolation('Unidade inválida ou fora do seu escopo.', 'invalid_branch');
    }

    private function validityOverlaps(ScheduleTemplate $t, ?string $from, ?string $until): bool
    {
        $aFrom = $t->valid_from?->toDateString() ?? '0000-01-01';
        $aUntil = $t->valid_until?->toDateString() ?? '9999-12-31';

        return $aFrom <= ($until ?? '9999-12-31') && ($from ?? '0000-01-01') <= $aUntil;
    }
}
