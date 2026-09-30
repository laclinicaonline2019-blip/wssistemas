<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'string', 'size:26'],
            'branch_id' => ['required', 'string', 'size:26'],
            'patient_id' => ['required', 'string', 'size:26'],
            'starts_at' => ['required', 'date'],
            'service_id' => ['nullable', 'string', 'size:26'],
            'is_overbook' => ['sometimes', 'boolean'],
            'payer_type' => ['sometimes', Rule::in(['private', 'insurance'])],
            'patient_insurance_id' => ['nullable', 'required_if:payer_type,insurance', 'string', 'size:26'],
            'channel' => ['sometimes', Rule::in(array_keys(Appointment::CHANNELS))],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function attributes(): array
    {
        return ['doctor_id' => 'médico', 'patient_id' => 'paciente', 'starts_at' => 'horário', 'patient_insurance_id' => 'convênio'];
    }
}
