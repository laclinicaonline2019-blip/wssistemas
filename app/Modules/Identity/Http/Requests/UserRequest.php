<?php

namespace App\Modules\Identity\Http\Requests;

use App\Core\Security\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeRoles();
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /** Remove linhas vazias do formulário dinâmico de perfis. */
    protected function normalizeRoles(): void
    {
        if ($this->has('roles') || $this->isMethod('PUT')) {
            $roles = array_values(array_filter((array) $this->input('roles', []), fn ($r) => is_array($r) && ! empty($r['role_id'])));
            $this->merge(['roles' => $roles]);
        }
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $creating = $user === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:150'],
            'email' => [$req, 'email:rfc', 'max:190', Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$creating ? 'required' : 'nullable', 'confirmed', PasswordRules::default()],
            'roles' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:20'],
            'roles.*.role_id' => ['required_with:roles', 'string', 'size:26'],
            'roles.*.branch_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'Este e-mail não está disponível.'];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'password' => 'senha', 'phone' => 'telefone', 'roles' => 'perfis'];
    }
}
