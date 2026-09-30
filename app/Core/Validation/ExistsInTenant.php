<?php

namespace App\Core\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Como "exists:", mas consultando pelo model (com o escopo da empresa atual):
 * IDs de outra clínica são tratados como inexistentes.
 */
class ExistsInTenant implements ValidationRule
{
    /** @param  class-string<Model>  $model */
    public function __construct(private readonly string $model) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->model::query()->whereKey($value)->exists()) {
            $fail('O :attribute selecionado é inválido.');
        }
    }
}
