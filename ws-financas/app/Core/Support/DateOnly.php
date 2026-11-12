<?php

namespace App\Core\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Data sem hora, gravada como "AAAA-MM-DD" em qualquer banco (comparações de texto corretas também no SQLite). */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : CarbonImmutable::parse((string) $value)->toDateString();
    }
}
