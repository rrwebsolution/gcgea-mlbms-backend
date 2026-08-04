<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'moduleKey' => $this->module_key,
            'label' => $this->label,
            'isEnabled' => $this->is_enabled,
            // Every workflow implicitly starts with the creator submitting the record —
            // that's recorded as an ApprovalAction (action: 'submitted'), never as a row in
            // workflow_stages, since it isn't an approver step and isn't editable here. Sent
            // separately from `stages` (not prepended into it) so a screen that lists the
            // stage pipeline can show it as step 1 without it ever being mistaken for a
            // configurable stage and round-tripped back through the stages update endpoint.
            'submissionStage' => [
                'sequence' => 0,
                'code' => 'submit',
                'label' => 'Application Submission',
                'description' => 'Submitted by the member/applicant or the staff member who created the record.',
            ],
            'stages' => WorkflowStageResource::collection($this->whenLoaded('stages')),
        ];
    }
}
