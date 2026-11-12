<?php

namespace App\Core\Security;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** Mínimo de 10 caracteres com letras maiúsculas e minúsculas, números e símbolos; opcionalmente checa vazamentos (HIBP). */
    public static function default(): Password
    {
        $rule = Password::min(10)->letters()->mixedCase()->numbers()->symbols();

        return env('PASSWORD_BREACH_CHECK', false) ? $rule->uncompromised() : $rule;
    }
}
