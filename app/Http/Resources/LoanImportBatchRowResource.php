<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LoanImportBatchRow */
class LoanImportBatchRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'sheet' => $this->sheet,
            'rowNumber' => $this->row_number,
            'sourceName' => $this->source_name,
            'memberId' => $this->member_id ? (string) $this->member_id : null,
            'memberName' => $this->member?->full_name,
            'loanId' => $this->loan_id ? (string) $this->loan_id : null,
            'loanReferenceNumber' => $this->loan?->application_number,
            'principal' => (float) $this->principal,
            'interest' => (float) $this->interest,
            'principalBalance' => (float) $this->principal_balance,
            'interestBalance' => (float) $this->interest_balance,
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }
}
