<?php

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $plan = $this->route('plan');
        $req = $plan ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:50', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('saas_plans', 'code')->ignore($plan?->id)],
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_monthly_cents' => [$req, 'integer', 'min:0'],
            'price_yearly_cents' => [$req, 'integer', 'min:0'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'is_active' => ['sometimes', 'boolean'],
            'limits' => ['sometimes', 'array'],
            'limits.max_users' => ['nullable', 'integer', 'min:1'],
            'limits.max_branches' => ['nullable', 'integer', 'min:1'],
            'limits.max_doctors' => ['nullable', 'integer', 'min:1'],
            'limits.storage_mb' => ['nullable', 'integer', 'min:0'],
            'limits.ai_enabled' => ['sometimes', 'boolean'],
            'limits.whatsapp_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
