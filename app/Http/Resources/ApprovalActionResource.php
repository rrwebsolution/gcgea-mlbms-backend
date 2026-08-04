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
            // Prefer the role that actually authorized this specific action (the stage's
            // assigned approver role) over the actor's primary role — a user can hold more
            // than one role (e.g. also Treasurer), so this correctly labels which hat they
            // were wearing for this action rather than always showing their primary title.
            // 'submitted'/'resubmitted' are the exception: their workflow_stage_id points at
            // the *next* (first) approver stage, not a stage this actor was acting under, so
            // that stage's approver role must never be attributed to the submitter.
            'performedByRole' => in_array($this->action, ['submitted', 'resubmitted'], true)
                ? $this->actor?->role?->name
                : ($this->stage?->approverRole?->name ?? $this->actor?->role?->name),
            'performedAt' => $this->acted_at?->toIso8601String(),
            'remarks' => $this->remarks,
        ];
    }
}
