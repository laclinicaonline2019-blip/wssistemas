<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $specialty = $this->route('specialty');
        $req = $specialty ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120', Rule::unique('specialties', 'name')->where('company_id', app(TenantContext::class)->companyId())->ignore($specialty?->id)],
            'cbo_code' => ['nullable', 'string', 'regex:/^\d{4,6}(-\d)?$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'cbo_code' => 'código CBO'];
    }
}
