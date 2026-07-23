<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'dateTime' => $this->date_time?->toIso8601String(),
            'userName' => $this->user_name,
            'roleName' => $this->role_name,
            'module' => $this->module,
            'action' => $this->action,
            'recordReference' => $this->record_reference,
            'oldValues' => $this->old_values,
            'newValues' => $this->new_values,
            'ipAddress' => $this->ip_address,
            'device' => $this->device,
            'status' => $this->status,
        ];
    }
}
