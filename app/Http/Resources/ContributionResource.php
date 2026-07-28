<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContributionResource extends JsonResource
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
            'officeName' => $this->member?->office?->name,
            'contributionPeriod' => $this->contribution_period,
            'contributionType' => $this->contribution_type,
            'amount' => (float) $this->amount,
            'paymentDate' => $this->payment_date?->toDateString(),
            'paymentMethod' => $this->payment_method,
            'officialReceiptNumber' => $this->official_receipt_number,
            'payrollReference' => $this->payroll_reference,
            'remarks' => $this->remarks,
            'encodedBy' => $this->encoded_by,
            'status' => $this->status,
            'fundAllocations' => $this->whenLoaded('fundAllocations', fn () => $this->fundAllocations->map(fn ($allocation) => [
                'fundId' => (string) $allocation->fund_id,
                'fundName' => $allocation->fund_name_snapshot,
                'allocatedAmount' => (float) $allocation->allocated_amount,
            ])->values()),
            'voidReason' => $this->void_reason,
            'voidedBy' => $this->voided_by,
            'voidedAt' => $this->voided_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
