<?php

namespace App\Modules\Messaging\Services;

/** Telefones brasileiros para o WhatsApp. */
final class Phone
{
    /** "11 99999-0000" → "5511999990000" (E.164 sem +). Null se inválido. */
    public static function e164(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if ($d === '') {
            return null;
        }
        if (strlen($d) >= 12 && str_starts_with($d, '55')) {
            return strlen($d) <= 13 ? $d : null;
        }

        return in_array(strlen($d), [10, 11], true) ? '55'.$d : null;
    }

    /**
     * Chave de comparação: DDD + 8 últimos dígitos. O WhatsApp pode devolver números
     * antigos sem o 9º dígito (55 11 9999-0000), então o 9 não entra na comparação.
     */
    public static function key(?string $phone): ?string
    {
        $e = self::e164($phone);

        return $e ? substr($e, 2, 2).substr($e, -8) : null;
    }
}
