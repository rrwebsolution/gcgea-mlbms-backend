<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'memberId' => (string) $this->member_id,
            'category' => $this->category,
            'fileName' => $this->file_name,
            'fileUrl' => $this->file_url,
            'uploadedAt' => $this->uploaded_at?->toIso8601String(),
            'uploadedBy' => $this->uploaded_by,
            'fileSize' => $this->formattedFileSize(),
        ];
    }
}
