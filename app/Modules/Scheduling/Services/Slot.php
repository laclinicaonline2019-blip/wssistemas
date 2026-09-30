<?php

namespace App\Modules\Scheduling\Services;

use Carbon\CarbonImmutable;

/** Um horário da grade em um dia específico. Horários em UTC; exibição no fuso da filial. */
final class Slot
{
    public const FREE = 'free';

    public const BOOKED = 'booked';

    public const BLOCKED = 'blocked';

    public const PAST = 'past';

    public const FULL = 'full';

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $status,
        public readonly string $templateId,
        public readonly string $doctorId,
        public readonly string $branchId,
        public readonly ?string $reason = null,
    ) {}

    public function isFree(): bool
    {
        return $this->status === self::FREE;
    }

    public function toArray(string $timezone): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'local_date' => $this->start->setTimezone($timezone)->format('Y-m-d'),
            'local_time' => $this->start->setTimezone($timezone)->format('H:i'),
            'status' => $this->status,
            'reason' => $this->reason,
            'doctor_id' => $this->doctorId,
            'branch_id' => $this->branchId,
            'template_id' => $this->templateId,
        ];
    }
}
