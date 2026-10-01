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

    /** Número por extenso (0–999), ex.: 15 → "quinze" — usado em atestados ("3 (três) dias"). */
    public static function numberInWords(int $n): string
    {
        $units = ['zero', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze',
            'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
        $tens = [2 => 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
        $hundreds = [1 => 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];

        if ($n < 0 || $n > 999) {
            return (string) $n;
        }
        if ($n < 20) {
            return $units[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)].($n % 10 ? ' e '.$units[$n % 10] : '');
        }
        if ($n === 100) {
            return 'cem';
        }

        return $hundreds[intdiv($n, 100)].($n % 100 ? ' e '.self::numberInWords($n % 100) : '');
    }

    /** Iniciais do nome ("Maria da Silva Souza" → "M. S. S.") — exibição pública mínima. */
    public static function initials(?string $name): string
    {
        return collect(preg_split('/\s+/', trim((string) $name)))
            ->reject(fn ($w) => $w === '' || in_array(mb_strtolower($w), ['da', 'de', 'do', 'das', 'dos', 'e'], true))
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).'.')->implode(' ');
    }
}
