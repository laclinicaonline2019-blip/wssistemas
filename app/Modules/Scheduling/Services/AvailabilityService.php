<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Organization\Models\Branch;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\ScheduleTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Motor de disponibilidade da agenda.
 *
 * Um horário só é oferecido (a humanos, à API ou à IA) se:
 *  - existe grade ativa e vigente para o médico/unidade no dia;
 *  - não há feriado nem bloqueio (do médico ou da unidade);
 *  - não é passado;
 *  - não há agendamento ativo sobreposto do mesmo médico (em QUALQUER unidade);
 *  - o limite do período (max_patients) e o limite diário do médico não foram atingidos.
 * O BookingService revalida tudo isso dentro de transação com lock antes de gravar.
 */
class AvailabilityService
{
    /**
     * Períodos de um médico em uma unidade num dia (data local da unidade).
     *
     * @return list<array{template: ScheduleTemplate, slots: list<Slot>, capacity: int, booked: int, overbooks: int, full: bool}>
     */
    public function day(Doctor $doctor, Branch $branch, CarbonImmutable $localDate, ?int $durationMinutes = null, ?string $ignoreAppointmentId = null): array
    {
        $tz = $branch->timezone;
        $date = $localDate->setTimezone($tz)->startOfDay();

        $templates = ScheduleTemplate::query()->active()
            ->where('doctor_id', $doctor->id)->where('branch_id', $branch->id)->where('weekday', $date->dayOfWeek)
            ->orderBy('start_time')->get()
            ->filter(fn (ScheduleTemplate $t) => $t->appliesOn($date));

        if ($templates->isEmpty()) {
            return [];
        }

        $dayStartUtc = $date->utc();
        $dayEndUtc = $date->endOfDay()->utc();

        $holiday = Holiday::query()->whereDate('date', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->first();

        $blocks = ScheduleBlock::query()
            ->where('starts_at', '<', $dayEndUtc)->where('ends_at', '>', $dayStartUtc)
            ->where(fn ($q) => $q->where('doctor_id', $doctor->id)->orWhere(fn ($w) => $w->whereNull('doctor_id')->where(fn ($b) => $b->whereNull('branch_id')->orWhere('branch_id', $branch->id))))
            ->get();

        $appointments = $this->doctorAppointments($doctor->id, $dayStartUtc, $dayEndUtc, $ignoreAppointmentId);
        $dailyCount = $appointments->where('is_overbook', false)->count();
        $dailyFull = $doctor->daily_limit !== null && $dailyCount >= $doctor->daily_limit;
        $now = CarbonImmutable::now('UTC');
        $result = [];

        foreach ($templates as $template) {
            [$periodStart, $periodEnd] = $this->periodBounds($template, $date);
            $inPeriod = $appointments->filter(fn ($a) => $a->starts_at >= $periodStart && $a->starts_at < $periodEnd && $a->branch_id === $branch->id);
            $booked = $inPeriod->where('is_overbook', false)->count();
            $overbooks = $inPeriod->where('is_overbook', true)->count();
            $capacity = $template->capacity();
            $full = $booked >= $capacity || $dailyFull;
            $duration = $durationMinutes ?? $template->slot_minutes;
            $slots = [];

            for ($start = $periodStart; $start < $periodEnd; $start = $start->addMinutes($template->slot_minutes)) {
                $end = $start->addMinutes($duration);
                [$status, $reason] = match (true) {
                    $holiday !== null => [Slot::BLOCKED, 'Feriado: '.$holiday->name],
                    ($block = $blocks->first(fn ($b) => $b->starts_at < $end && $b->ends_at > $start)) !== null => [Slot::BLOCKED, $block->reason],
                    $appointments->contains(fn ($a) => ! $a->is_overbook && $a->starts_at < $end && $a->ends_at > $start) => [Slot::BOOKED, null],
                    $start < $now => [Slot::PAST, null],
                    $end > $periodEnd => [Slot::BLOCKED, 'Duração excede o período'],
                    $full => [Slot::FULL, $dailyFull ? 'Limite diário do médico atingido' : 'Limite de pacientes do período atingido'],
                    default => [Slot::FREE, null],
                };

                $slots[] = new Slot($start, $end, $status, $template->id, $doctor->id, $branch->id, $reason);
            }

            $result[] = compact('template', 'slots', 'capacity', 'booked', 'overbooks', 'full');
        }

        return $result;
    }

    /** @return list<Slot> */
    public function freeSlots(Doctor $doctor, Branch $branch, CarbonImmutable $from, CarbonImmutable $to, ?int $durationMinutes = null): array
    {
        $free = [];

        for ($d = $from->setTimezone($branch->timezone)->startOfDay(); $d <= $to; $d = $d->addDay()) {
            foreach ($this->day($doctor, $branch, $d, $durationMinutes) as $period) {
                foreach ($period['slots'] as $slot) {
                    if ($slot->isFree()) {
                        $free[] = $slot;
                    }
                }
            }
        }

        return $free;
    }

    /**
     * Próximos horários livres (para recepção e IA): por médico ou por especialidade.
     *
     * @return list<Slot>
     */
    public function next(Branch $branch, ?Doctor $doctor = null, ?string $specialtyId = null, int $limit = 5, int $searchDays = 60, ?CarbonImmutable $from = null): array
    {
        $doctors = $doctor ? collect([$doctor]) : Doctor::query()->active()
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id))
            ->when($specialtyId, fn ($q) => $q->whereHas('specialties', fn ($s) => $s->where('specialties.id', $specialtyId)))
            ->get();

        $found = [];
        $day = ($from ?? CarbonImmutable::now())->setTimezone($branch->timezone)->startOfDay();

        for ($i = 0; $i < $searchDays && count($found) < $limit; $i++, $day = $day->addDay()) {
            $daySlots = [];

            foreach ($doctors as $d) {
                foreach ($this->day($d, $branch, $day) as $period) {
                    if ($specialtyId && $period['template']->specialty_id && $period['template']->specialty_id !== $specialtyId) {
                        continue;
                    }
                    foreach ($period['slots'] as $slot) {
                        if ($slot->isFree()) {
                            $daySlots[] = $slot;
                        }
                    }
                }
            }

            usort($daySlots, fn (Slot $a, Slot $b) => $a->start <=> $b->start);
            $found = array_merge($found, array_slice($daySlots, 0, $limit - count($found)));
        }

        return $found;
    }

    /** Limites do período (UTC) de uma grade numa data local. */
    public function periodBounds(ScheduleTemplate $template, CarbonImmutable $localDate): array
    {
        $date = $localDate->startOfDay();
        $start = CarbonImmutable::parse($date->toDateString().' '.$template->start_time, $date->timezone)->utc();
        $end = CarbonImmutable::parse($date->toDateString().' '.$template->end_time, $date->timezone)->utc();

        return [$start, $end];
    }

    /** Agendamentos ativos do médico no intervalo, em todas as unidades. */
    public function doctorAppointments(string $doctorId, CarbonImmutable $from, CarbonImmutable $to, ?string $ignoreId = null): Collection
    {
        return Appointment::query()->active()
            ->where('doctor_id', $doctorId)
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get(['id', 'branch_id', 'starts_at', 'ends_at', 'is_overbook', 'status']);
    }
}
