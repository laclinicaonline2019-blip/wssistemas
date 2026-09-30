<?php

namespace App\Core\Security;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    public static function default(): Password
    {
        $rule = Password::min(config('aivexa.security.password_min_length'))
            ->letters()->mixedCase()->numbers()->symbols();

        return config('aivexa.security.password_breach_check') ? $rule->uncompromised() : $rule;
    }
}
