<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Core\Validation\ExistsInTenant;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Scheduling\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $room = $this->route('room');
        $req = $room ? 'sometimes' : 'required';

        return [
            'branch_id' => [$req, 'string', 'size:26'],
            'name' => [$req, 'string', 'max:80', Rule::unique('rooms', 'name')->where('branch_id', $this->input('branch_id', $room?->branch_id))->where('number', $this->input('number', $room?->number))->ignore($room?->id)],
            'number' => ['nullable', 'string', 'max:20'],
            'specialty_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Specialty::class)],
            'doctor_id' => ['nullable', 'string', 'size:26', new ExistsInTenant(Doctor::class)],
            'equipment' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(array_keys(Room::STATUSES))],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Já existe uma sala com este nome e número nesta unidade.'];
    }
}
