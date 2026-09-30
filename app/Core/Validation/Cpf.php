<?php

namespace App\Core\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valida CPF (dígitos verificadores). */
class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('O :attribute informado não é um CPF válido.');
        }
    }

    public static function isValid(string $value): bool
    {
        $cpf = preg_replace('/\D/', '', $value) ?? '';

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
