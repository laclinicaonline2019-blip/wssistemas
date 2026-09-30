<?php

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\Models\SaasPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaasPlan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price_monthly_cents' => $this->price_monthly_cents,
            'price_yearly_cents' => $this->price_yearly_cents,
            'trial_days' => $this->trial_days,
            'limits' => $this->limits,
            'is_active' => $this->is_active,
        ];
    }
}
