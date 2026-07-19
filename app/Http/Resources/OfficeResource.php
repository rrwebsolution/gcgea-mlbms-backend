<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?? '',
            'status' => $this->status,
            'memberCount' => $this->members_count ?? 0,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
