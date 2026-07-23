<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately shaped identically to the existing loan ApprovalHistoryEntryResource
 * so the frontend's ApprovalHistoryEntry type and ApprovalTimeline component need
 * zero changes to consume either.
 */
class ApprovalActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'action' => $this->action,
            'performedBy' => $this->actor?->full_name ?? 'System',
            'performedAt' => $this->acted_at?->toIso8601String(),
            'remarks' => $this->remarks,
        ];
    }
}
