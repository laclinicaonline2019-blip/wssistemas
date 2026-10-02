<?php

namespace App\Modules\Patients\Http\Requests;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Core\Validation\BrazilianStates;
use App\Core\Validation\Cpf;
use App\Core\Validation\ExistsInTenant;
use App\Modules\Insurance\Models\InsurancePlan;
use App\Modules\Insurance\Models\Insurer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['cpf', 'cns', 'phone', 'whatsapp', 'zip_code'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = Format::digits($this->input($key));
            }
        }

        // Linhas vazias dos formulários dinâmicos são descartadas.
        if ($this->has('contacts')) {
            $merge['contacts'] = array_values(array_filter((array) $this->input('contacts'), fn ($c) => is_array($c) && filled($c['name'] ?? null)));
        }

        if ($this->has('insurances')) {
            $merge['insurances'] = array_values(array_filter((array) $this->input('insurances'), fn ($i) => is_array($i) && (filled($i['insurer_name'] ?? null) || filled($i['insurer_id'] ?? null))));
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $patient = $this->route('patient');
        $req = $patient ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'min:3', 'max:150'],
            'social_name' => ['nullable', 'string', 'max:150'],
            'cpf' => ['nullable', new Cpf,
                Rule::unique('patients', 'cpf')->where('company_id', app(TenantContext::class)->companyId())->whereNull('deleted_at')->ignore($patient?->id)],
            'rg' => ['nullable', 'string', 'max:20'],
            'rg_issuer' => ['nullable', 'string', 'max:20'],
            'cns' => ['nullable', 'digits:15'],
            'birth_date' => [$req, 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'sex' => ['nullable', Rule::in(['F', 'M', 'I'])],
            'gender_identity' => ['nullable', 'string', 'max:60'],
            'mother_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'digits_between:10,11'],
            'whatsapp' => ['nullable', 'digits_between:10,11'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'zip_code' => ['nullable', 'digits:8'],
            'street' => ['nullable', 'string', 'max:190'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(BrazilianStates::ALL)],
            'preferred_contact' => ['nullable', Rule::in(['whatsapp', 'phone', 'email'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'home_branch_id' => ['nullable', 'string', 'size:26'],
            'confirm_duplicate' => ['sometimes', 'boolean'],

            'contacts' => ['sometimes', 'array', 'max:10'],
            'contacts.*.type' => ['required', Rule::in(['guardian', 'emergency', 'other'])],
            'contacts.*.name' => ['required', 'string', 'max:150'],
            'contacts.*.relationship' => ['nullable', 'string', 'max:60'],
            'contacts.*.cpf' => ['nullable', new Cpf],
            'contacts.*.phone' => ['nullable', 'string', 'max:20'],
            'contacts.*.email' => ['nullable', 'email:rfc', 'max:190'],

            'insurances' => ['sometimes', 'array', 'max:5'],
            'insurances.*.id' => ['nullable', 'string', 'size:26'],
            'insurances.*.insurer_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Insurer::class)],
            'insurances.*.plan_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(InsurancePlan::class)],
            'insurances.*.insurer_name' => ['required_without:insurances.*.insurer_id', 'nullable', 'string', 'max:120'],
            'insurances.*.plan_name' => ['nullable', 'string', 'max:120'],
            'insurances.*.card_number' => ['required', 'string', 'max:40'],
            'insurances.*.valid_until' => ['nullable', 'date'],
            'insurances.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /** Plano escolhido precisa ser do convênio escolhido. */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('insurances', []) as $i => $row) {
                if (! empty($row['plan_id']) && DB::table('insurance_plans')->where('id', $row['plan_id'])->value('insurer_id') !== ($row['insurer_id'] ?? null)) {
                    $validator->errors()->add("insurances.{$i}.plan_id", 'O plano não pertence ao convênio selecionado.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'cpf.unique' => 'Já existe um paciente com este CPF.',
            'birth_date.before_or_equal' => 'A data de nascimento não pode ser futura.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome', 'birth_date' => 'data de nascimento', 'phone' => 'telefone', 'mother_name' => 'nome da mãe',
            'zip_code' => 'CEP', 'state' => 'UF', 'contacts.*.name' => 'nome do contato', 'contacts.*.cpf' => 'CPF do contato',
            'insurances.*.insurer_name' => 'convênio', 'insurances.*.card_number' => 'número da carteirinha',
        ];
    }
}
