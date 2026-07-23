<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description ?? '',
            'isSystemRole' => $this->is_system_role,
            'status' => $this->status,
            'permissions' => $this->permissions->pluck('code'),
            'userCount' => ($this->primary_users_count ?? $this->primaryUsers()->count())
                + ($this->additional_users_count ?? $this->additionalUsers()->count()),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
