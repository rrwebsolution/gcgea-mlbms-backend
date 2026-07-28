<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LoanImportBatch */
class LoanImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'originalFilename' => $this->original_filename,
            'balancePeriod' => $this->balance_period,
            'totalRows' => $this->total_rows,
            'createdCount' => $this->created_count,
            'skippedCount' => $this->skipped_count,
            'errors' => $this->errors ?? [],
            'uploadedBy' => $this->uploadedBy?->full_name,
            'committedAt' => optional($this->committed_at)->toISOString(),
        ];
    }
}
