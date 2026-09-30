<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'is_super_admin' => $this->is_super_admin,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roleAssignments', fn () => $this->roleAssignments->map(fn ($a) => [
                'assignment_id' => $a->id,
                'role_id' => $a->role_id,
                'role_key' => $a->role?->key,
                'role_name' => $a->role?->name,
                'branch_id' => $a->branch_id,
                'branch_name' => $a->branch?->name,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
