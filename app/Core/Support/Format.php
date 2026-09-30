<?php

namespace App\Core\Support;

use Illuminate\Support\Str;

/** Normalização e formatação de dados brasileiros. */
final class Format
{
    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    public static function cpf(?string $cpf): ?string
    {
        return $cpf && strlen($cpf) === 11 ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $cpf) : $cpf;
    }

    /** CPF mascarado para listagens (LGPD — minimização): ***.456.789-** */
    public static function cpfMasked(?string $cpf): ?string
    {
        return $cpf && strlen($cpf) === 11 ? '***.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-**' : null;
    }

    public static function cnpj(?string $cnpj): ?string
    {
        return $cnpj && strlen($cnpj) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $cnpj) : $cnpj;
    }

    public static function phone(?string $phone): ?string
    {
        return match (strlen((string) $phone)) {
            11 => preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $phone),
            10 => preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $phone),
            default => $phone,
        };
    }

    public static function cep(?string $cep): ?string
    {
        return $cep && strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : $cep;
    }

    /** Minúsculas e sem acentos — usado para busca por nome. */
    public static function searchable(?string $text): string
    {
        return Str::of((string) $text)->ascii()->lower()->squish()->toString();
    }

    public static function personName(?string $name): ?string
    {
        return $name === null ? null : Str::of($name)->squish()->toString();
    }
}
