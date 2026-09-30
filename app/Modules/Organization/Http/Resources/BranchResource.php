<?php

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_headquarters' => $this->is_headquarters,
            'document' => $this->document,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => [
                'zip_code' => $this->zip_code,
                'street' => $this->street,
                'number' => $this->number,
                'complement' => $this->complement,
                'district' => $this->district,
                'city' => $this->city,
                'state' => $this->state,
            ],
            'timezone' => $this->timezone,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
