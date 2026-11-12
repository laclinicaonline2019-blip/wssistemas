<?php

use App\Core\Access\Permissions;
use App\Core\Support\Money;

if (! function_exists('brl')) {
    function brl(int|float|null $cents): string
    {
        return Money::brl($cents);
    }
}

if (! function_exists('can_do')) {
    function can_do(string $ability): bool
    {
        return Permissions::can($ability);
    }
}
