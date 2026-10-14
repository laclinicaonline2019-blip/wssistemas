<?php

namespace App\Modules\Sprint\Models;

/** Contas de divisão em centavos (sem ponto flutuante). */
final class SplitMath
{
    /** Parte sobre $amount: percentual em centésimos de % (6000 = 60%) ou valor fixo em centavos. */
    public static function share(int $amount, ?string $type, ?int $value): int
    {
        if (! $type || ! $value || $amount <= 0) {
            return 0;
        }

        return $type === 'percent' ? intdiv($amount * min($value, 10000) + 5000, 10000) : min($value, $amount);
    }

    /** Proporção de $part em $amount sobre $total (arredondada). */
    public static function prorate(int $amount, int $part, int $total): int
    {
        return $total <= 0 ? 0 : intdiv($amount * $part * 2 + $total, $total * 2);
    }

    public static function bp(int $amount, int $bp): int
    {
        return $bp <= 0 ? 0 : intdiv($amount * $bp + 5000, 10000);
    }

    public static function label(?string $type, ?int $value): string
    {
        if (! $type) {
            return '—';
        }

        return $type === 'percent' ? number_format(($value ?? 0) / 100, 2, ',', '.').'%' : 'R$ '.number_format(($value ?? 0) / 100, 2, ',', '.');
    }
}
