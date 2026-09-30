<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Core\Validation\BrazilianStates;
use App\Core\Validation\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['cpf', 'phone', 'crm'] as $key) {
            if ($this->filled($key)) {
                $merge[$key] = Format::digits($this->input($key));
            }
        }

        if ($this->filled('crm_state')) {
            $merge['crm_state'] = strtoupper((string) $this->input('crm_state'));
        }

        // Formulário web: especialidades como checkboxes (specialty_ids[]) + RQE por especialidade.
        if ($this->has('specialty_ids')) {
            $merge['specialties'] = collect((array) $this->input('specialty_ids'))
                ->map(fn ($id) => ['id' => $id, 'rqe' => $this->input("rqe.{$id}") ?: null])->values()->all();
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $doctor = $this->route('doctor');
        $req = $doctor ? 'sometimes' : 'required';
        $company = app(TenantContext::class)->companyId();

        return [
            'name' => [$req, 'string', 'max:150'],
            'social_name' => ['nullable', 'string', 'max:150'],
            'crm' => [$req, 'string', 'max:12', 'regex:/^\d{1,12}$/',
                Rule::unique('doctors', 'crm')->where('company_id', $company)->where('crm_state', $this->input('crm_state', $doctor?->crm_state))->whereNull('deleted_at')->ignore($doctor?->id)],
            'crm_state' => [$req, Rule::in(BrazilianStates::ALL)],
            'cpf' => ['nullable', new Cpf],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'min:10', 'max:11'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'user_id' => ['nullable', 'string', 'size:26'],
            'specialties' => ['sometimes', 'array', 'max:10'],
            'specialties.*.id' => ['required', 'string', 'size:26'],
            'specialties.*.rqe' => ['nullable', 'string', 'max:20'],
            'branches' => ['sometimes', 'array'],
            'branches.*' => ['string', 'size:26'],
        ];
    }

    public function messages(): array
    {
        return ['crm.unique' => 'Já existe um médico com este CRM/UF.'];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'crm_state' => 'UF do CRM', 'phone' => 'telefone', 'specialties' => 'especialidades', 'branches' => 'filiais'];
    }
}
