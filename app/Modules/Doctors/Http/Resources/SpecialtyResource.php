<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Specialty */
class SpecialtyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'cbo_code' => $this->cbo_code,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'doctors_count' => $this->whenCounted('doctors'),
            'rqe' => $this->whenPivotLoaded('doctor_specialty', fn () => $this->pivot->rqe),
        ];
    }
}
