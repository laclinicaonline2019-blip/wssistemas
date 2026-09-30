<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'string', 'size:26'],
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
