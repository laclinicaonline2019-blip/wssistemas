<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Remove linhas vazias do formulário dinâmico de perfis. */
    protected function normalizeRoles(): void
    {
        if ($this->has('roles') || $this->isMethod('PUT')) {
            $roles = array_values(array_filter((array) $this->input('roles', []), fn ($r) => is_array($r) && ! empty($r['role_id'])));
            $this->merge(['roles' => $roles]);
        }
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeRoles();
    }

    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:20'],
            'roles.*.role_id' => ['required', 'string', 'size:26'],
            'roles.*.branch_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
