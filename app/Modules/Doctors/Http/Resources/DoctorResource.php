<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Doctor */
class DoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'social_name' => $this->social_name,
            'display_name' => $this->displayName(),
            'crm' => $this->crm,
            'crm_state' => $this->crm_state,
            'registration' => $this->registration(),
            'cpf' => $this->cpf,
            'email' => $this->email,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'status' => $this->status,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null),
            'specialties' => SpecialtyResource::collection($this->whenLoaded('specialties')),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map->only(['id', 'name'])->values()),
        ];
    }
}
