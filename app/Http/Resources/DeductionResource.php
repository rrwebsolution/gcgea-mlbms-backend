<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeductionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'referenceNumber' => $this->reference_number,
            'memberId' => (string) $this->member_id,
            'memberNumber' => $this->member?->member_number,
            'memberName' => $this->member?->full_name,
            'deductionTypeId' => (string) $this->deduction_type_id,
            'deductionTypeName' => $this->deductionType?->name,
            'deductionTypeCode' => $this->deductionType?->code,
            'period' => $this->period,
            'amount' => (float) $this->amount,
            'paymentDate' => $this->payment_date?->toDateString(),
            'payrollReference' => $this->payroll_reference,
            'remarks' => $this->remarks,
            'encodedBy' => $this->encoded_by,
            'status' => $this->status,
            'voidReason' => $this->void_reason,
            'voidedBy' => $this->voided_by,
            'voidedAt' => $this->voided_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
