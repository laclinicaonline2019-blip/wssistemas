<?php

namespace App\Core\Support;

use Carbon\CarbonImmutable;

final class Months
{
    public const NAMES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public static function add(string $ym, int $n): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $ym)->addMonthsNoOverflow($n)->format('Y-m');
    }

    public static function dateIn(string $ym, int $day): string
    {
        $d = CarbonImmutable::createFromFormat('!Y-m', $ym);

        return $d->setDay(max(1, min($day, $d->daysInMonth)))->toDateString();
    }

    public static function valid(?string $ym): bool
    {
        return is_string($ym) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) === 1;
    }

    public static function label(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));

        return self::NAMES[$m - 1].' de '.$y;
    }
}
