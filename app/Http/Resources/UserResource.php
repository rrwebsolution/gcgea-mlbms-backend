<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a User model into the exact `AuthUser` shape the frontend expects
 * (see src/types/user.ts) — camelCase keys, string ids — so the frontend
 * service layer needs no transform step.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'fullName' => $this->full_name,
            'username' => $this->username,
            'email' => $this->email,
            'roleId' => $this->role_id !== null ? (string) $this->role_id : null,
            'roleName' => $this->role?->name,
            'roleIds' => $this->allRoles()->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'permissions' => $this->effectivePermissionCodes(),
            'avatarUrl' => $this->avatar_url,
            'status' => $this->status,
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            'requirePasswordChange' => (bool) $this->require_password_change,
        ];
    }
}
