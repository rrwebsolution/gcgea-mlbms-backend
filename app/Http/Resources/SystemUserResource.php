<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a User model into the `SystemUser` shape the frontend's admin Users
 * module expects (see src/types/user.ts) — distinct from UserResource, which
 * shapes the leaner `AuthUser` returned by /auth/login and /auth/me.
 */
class SystemUserResource extends JsonResource
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
            'contactNumber' => $this->contact_number,
            'roleId' => $this->role_id !== null ? (string) $this->role_id : null,
            'roleName' => $this->role?->name,
            'additionalRoleIds' => $this->additionalRoles->pluck('id')->map(fn ($id) => (string) $id)->values(),
            'memberId' => $this->member_id !== null ? (string) $this->member_id : null,
            'memberName' => $this->member?->full_name,
            'memberNumber' => $this->member?->member_number,
            'additionalPermissions' => [],
            'allowedPermissions' => $this->allowedPermissions->pluck('code'),
            'deniedPermissions' => $this->deniedPermissions->pluck('code'),
            'requirePasswordChange' => $this->require_password_change,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'avatarUrl' => $this->avatar_url,
        ];
    }
}
