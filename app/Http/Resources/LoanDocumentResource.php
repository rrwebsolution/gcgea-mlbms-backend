<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'requirementLabel' => $this->requirement_label,
            'fileName' => $this->file_name,
            'fileUrl' => $this->file_url,
            'fileSizeBytes' => $this->file_size_bytes,
            'uploadedBy' => $this->uploaded_by,
            'uploadedAt' => $this->uploaded_at?->toIso8601String(),
        ];
    }
}
