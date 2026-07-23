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
            'stages' => WorkflowStageResource::collection($this->whenLoaded('stages')),
        ];
    }
}
