<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BenefitPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'paymentReferenceNumber' => $this->payment_reference_number,
            'benefitApplicationId' => (string) $this->benefit_application_id,
            'applicationNumber' => $this->benefitApplication?->application_number,
            'memberId' => (string) $this->member_id,
            'memberName' => $this->member?->full_name,
            'paymentDate' => $this->payment_date?->toDateString(),
            'amountPaid' => (float) $this->amount_paid,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'voidReason' => $this->void_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
