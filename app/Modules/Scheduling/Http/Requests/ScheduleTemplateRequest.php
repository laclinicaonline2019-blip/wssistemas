<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Core\Validation\ExistsInTenant;
use App\Modules\Doctors\Models\Specialty;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->route('template') ? 'sometimes' : 'required';

        return [
            'branch_id' => [$req, 'string', 'size:26'],
            'room_id' => ['nullable', 'string', 'size:26'],
            'specialty_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Specialty::class)],
            'weekday' => [$req, 'integer', 'between:0,6'],
            'start_time' => [$req, 'date_format:H:i'],
            'end_time' => [$req, 'date_format:H:i', 'after:start_time'],
            'slot_minutes' => [$req, 'integer', 'min:5', 'max:240'],
            'max_patients' => ['nullable', 'integer', 'min:1', 'max:500'],
            'max_overbooks' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['weekday' => 'dia da semana', 'start_time' => 'início', 'end_time' => 'fim', 'slot_minutes' => 'duração do horário',
            'max_patients' => 'limite de pacientes', 'max_overbooks' => 'encaixes', 'valid_until' => 'fim da vigência'];
    }
}
