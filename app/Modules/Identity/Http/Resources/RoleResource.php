<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'is_locked' => $this->is_locked,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissionKeys()),
            'users_count' => $this->whenCounted('assignments'),
        ];
    }
}
