<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DoctorServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Formulário web envia o valor em reais ("150,00"); a API envia price_cents. */
    protected function prepareForValidation(): void
    {
        if ($this->filled('price') && ! $this->has('price_cents')) {
            $value = str_replace(['R$', ' ', '.'], '', (string) $this->input('price'));
            $this->merge(['price_cents' => (int) round(((float) str_replace(',', '.', $value)) * 100)]);
        }
    }

    public function rules(): array
    {
        $service = $this->route('service');
        $req = $service ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:100', Rule::unique('doctor_services', 'name')->where('doctor_id', $this->route('doctor')?->id ?? $service?->doctor_id)->ignore($service?->id)],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'price_cents' => [$req, 'integer', 'min:0', 'max:100000000'],
            'accepts_private' => ['sometimes', 'boolean'],
            'accepts_insurance' => ['sometimes', 'boolean'],
            'is_telemedicine' => ['sometimes', 'boolean'],
            'is_return' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'price_cents' => 'valor', 'duration_minutes' => 'duração'];
    }
}
