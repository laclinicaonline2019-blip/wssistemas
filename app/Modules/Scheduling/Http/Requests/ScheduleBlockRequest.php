<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Core\Validation\ExistsInTenant;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\ScheduleBlock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doctor_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Doctor::class)],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'type' => ['required', Rule::in(array_keys(ScheduleBlock::TYPES))],
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['starts_at' => 'início', 'ends_at' => 'fim', 'reason' => 'motivo'];
    }
}
