<?php

namespace App\Core\Support;

final class Money
{
    /** "1.200,50" | "1200.5" | "R$ 5.000" | "5 mil" → centavos; null se inválido. */
    public static function parse(string|int|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (int) round($value * 100);
        }
        $s = trim(preg_replace('/r\$\s*/i', '', $value) ?? '');
        $mil = false;
        if (preg_match('/^(.*?)\s*(mil|k)$/iu', $s, $m)) {
            $s = trim($m[1]);
            $mil = true;
        }
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        if (! is_numeric($s)) {
            return null;
        }
        $v = (float) $s * ($mil ? 1000 : 1);

        return $v >= 0 ? (int) round($v * 100) : null;
    }

    public static function brl(int|float|null $cents): string
    {
        $v = ((int) round((float) $cents)) / 100;

        return ($v < 0 ? '-' : '').'R$ '.number_format(abs($v), 2, ',', '.');
    }
}
