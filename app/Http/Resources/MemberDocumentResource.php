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
            // Always serve through the authenticated API rather than a
            // Railway/local absolute storage URL. Relative /api URLs work
            // both locally and through the Vercel backend proxy.
            'fileUrl' => "/api/members/{$this->member_id}/documents/{$this->id}/file",
            'uploadedAt' => $this->uploaded_at?->toIso8601String(),
            'uploadedBy' => $this->uploaded_by,
            'fileSize' => $this->formattedFileSize(),
        ];
    }
}
