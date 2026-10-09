<?php

namespace App\Core\Security;

use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    public static function default(): Password
    {
        $rule = Password::min(config('wsclinic.security.password_min_length'))
            ->letters()->mixedCase()->numbers()->symbols();

        return config('wsclinic.security.password_breach_check') ? $rule->uncompromised() : $rule;
    }
}
