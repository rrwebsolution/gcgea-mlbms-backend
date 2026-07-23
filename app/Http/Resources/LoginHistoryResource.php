<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'userId' => (string) $this->user_id,
            'loginAt' => $this->login_at?->toIso8601String(),
            'ipAddress' => $this->ip_address,
            'device' => $this->device,
            'status' => $this->status,
        ];
    }
}
