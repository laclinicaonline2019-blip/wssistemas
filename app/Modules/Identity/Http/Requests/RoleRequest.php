<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->route('role') ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => [$req, 'array'],
            'permissions.*' => ['string', 'max:100'],
        ];
    }
}
