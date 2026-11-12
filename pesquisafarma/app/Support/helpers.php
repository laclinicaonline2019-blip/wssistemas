<?php

if (! function_exists('money')) {
    function money(?float $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}

if (! function_exists('minutes_label')) {
    function minutes_label(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return number_format($minutes, $minutes < 10 ? 1 : 0, ',', '.').' min';
        }

        return floor($minutes / 60).'h'.str_pad((string) round(fmod($minutes, 60)), 2, '0', STR_PAD_LEFT);
    }
}
