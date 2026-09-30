<?php

namespace App\Modules\Platform\Http\Requests;

use App\Core\Security\PasswordRules;
use App\Core\Validation\Cnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document' => preg_replace('/\D/', '', (string) $this->input('document')),
            'admin_email' => mb_strtolower(trim((string) $this->input('admin_email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['required', 'string', 'max:200'],
            'document' => ['required', new Cnpj, Rule::unique('companies', 'document')->whereNull('deleted_at')],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'saas_plan_id' => ['nullable', 'exists:saas_plans,id'],
            'status' => ['sometimes', Rule::in(['trial', 'active'])],
            'headquarters_name' => ['required', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
            'admin_name' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'admin_password' => ['required', PasswordRules::default()],
        ];
    }

    public function attributes(): array
    {
        return [
            'legal_name' => 'razão social', 'trade_name' => 'nome fantasia', 'document' => 'CNPJ',
            'headquarters_name' => 'nome da matriz', 'admin_name' => 'nome do administrador',
            'admin_email' => 'e-mail do administrador', 'admin_password' => 'senha do administrador',
        ];
    }
}
