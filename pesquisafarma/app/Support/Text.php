<?php

namespace App\Support;

use Illuminate\Support\Str;

class Text
{
    public static function slug(?string $value): string
    {
        return Str::slug(Str::ascii(mb_strtolower(trim((string) $value))), ' ');
    }

    /** Telefone só com dígitos, com DDI 55 (padrão WhatsApp). */
    public static function phone(?string $value): string
    {
        $d = preg_replace('/\D+/', '', (string) $value);
        if ($d === '') {
            return '';
        }
        if (strlen($d) <= 11) {
            $d = '55'.$d;
        }

        return $d;
    }

    public static function formatPhone(?string $value): string
    {
        $d = preg_replace('/\D+/', '', (string) $value);
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }

        return (string) $value;
    }

    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    /** "1.234,56" | "1234.56" | "R$ 12,90" → 1234.56 */
    public static function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }
        $v = preg_replace('/[^\d,.\-]/', '', (string) $value);
        if ($v === '' || $v === '-') {
            return null;
        }
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }

        return round((float) $v, 2);
    }
}
