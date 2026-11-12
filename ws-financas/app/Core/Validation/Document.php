<?php

namespace App\Core\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CPF ou CNPJ válido (dígitos verificadores). */
class Document implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $d = preg_replace('/\D/', '', (string) $value) ?? '';
        if ($d === '') {
            return;
        }
        if (! (strlen($d) === 11 ? self::cpf($d) : (strlen($d) === 14 && self::cnpj($d)))) {
            $fail('CPF ou CNPJ inválido.');
        }
    }

    public static function cpf(string $c): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += (int) $c[$i] * (($t + 1) - $i);
            }
            if ((int) $c[$t] !== ((10 * $s) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    public static function cnpj(string $c): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }
        foreach ([12, 13] as $t) {
            $w = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += (int) $c[$i] * $w[$i];
            }
            $r = $s % 11 < 2 ? 0 : 11 - $s % 11;
            if ((int) $c[$t] !== $r) {
                return false;
            }
        }

        return true;
    }
}
