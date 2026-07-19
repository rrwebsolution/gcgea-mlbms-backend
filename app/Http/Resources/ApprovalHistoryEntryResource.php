<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalHistoryEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'action' => $this->action,
            'performedBy' => $this->performed_by,
            'performedAt' => $this->performed_at?->toIso8601String(),
            'remarks' => $this->remarks,
        ];
    }
}
