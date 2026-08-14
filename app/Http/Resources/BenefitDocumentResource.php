<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BenefitDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'benefitId' => (string) $this->benefit_application_id,
            'requirementLabel' => $this->requirement_label,
            'fileName' => $this->file_name,
            'fileUrl' => "/api/benefits/{$this->benefit_application_id}/documents/{$this->id}/file",
            'fileSizeBytes' => (int) $this->file_size_bytes,
            'uploadedBy' => $this->uploaded_by,
            'uploadedAt' => $this->uploaded_at?->toIso8601String(),
        ];
    }
}
