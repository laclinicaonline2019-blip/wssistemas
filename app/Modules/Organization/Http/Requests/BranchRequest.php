<?php

namespace App\Modules\Organization\Http\Requests;

use App\Core\Tenancy\TenantContext;
use App\Core\Validation\Cnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorização na rota (permission:) e no BranchService
    }

    protected function prepareForValidation(): void
    {
        $normalize = [
            'document' => fn ($v) => preg_replace('/\D/', '', (string) $v),
            'zip_code' => fn ($v) => preg_replace('/\D/', '', (string) $v),
            'state' => fn ($v) => strtoupper((string) $v),
            'code' => fn ($v) => strtoupper((string) $v),
        ];

        foreach ($normalize as $key => $fn) {
            if ($this->filled($key)) {
                $this->merge([$key => $fn($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        $branch = $this->route('branch');
        $partial = $this->isMethod('PATCH');
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:150'],
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Z0-9_\-]+$/',
                Rule::unique('branches', 'code')
                    ->where('company_id', app(TenantContext::class)->companyId())
                    ->whereNull('deleted_at')
                    ->ignore($branch?->id)],
            'document' => ['nullable', new Cnpj],
            'cnes' => ['nullable', 'regex:/^\d{7}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'zip_code' => ['nullable', 'digits:8'],
            'street' => ['nullable', 'string', 'max:190'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2', 'alpha'],
            'timezone' => ['sometimes', 'timezone:all'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'code' => 'código', 'document' => 'CNPJ', 'cnes' => 'CNES', 'zip_code' => 'CEP', 'state' => 'UF'];
    }
}
