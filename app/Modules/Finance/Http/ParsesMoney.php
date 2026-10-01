<?php

namespace App\Modules\Finance\Http;

use App\Core\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Converte valores digitados ("1.234,56", "250", ou inteiro em centavos na API) para centavos. */
trait ParsesMoney
{
    protected function cents(Request $request, string $field, bool $required = true, int $min = 1, string $label = 'valor'): ?int
    {
        $raw = $request->input($field);

        if ($raw === null || $raw === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => "Informe o {$label}."]);
            }

            return null;
        }

        // API: "_cents" já vem em centavos (inteiro).
        $cents = str_ends_with($field, '_cents') ? (filter_var($raw, FILTER_VALIDATE_INT) === false ? null : (int) $raw) : Format::parseMoney($raw);

        if ($cents === null || $cents < $min || $cents > 99_999_999_99) {
            throw ValidationException::withMessages([$field => "O {$label} informado é inválido."]);
        }

        return $cents;
    }
}
