<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BenefitApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'applicationNumber' => $this->application_number,
            'applicationDate' => $this->application_date?->toDateString(),
            'memberId' => (string) $this->member_id,
            'memberNumber' => $this->member?->member_number,
            'memberName' => $this->member?->full_name,
            'officeName' => $this->member?->office?->name,
            'benefitTypeId' => (string) $this->benefit_type_id,
            'benefitTypeName' => $this->benefitType?->name,
            'requestedAmount' => (float) $this->requested_amount,
            'approvedAmount' => $this->approved_amount !== null ? (float) $this->approved_amount : null,
            'reason' => $this->reason,
            'incidentDate' => $this->incident_date?->toDateString(),
            'beneficiaryOrRecipient' => $this->beneficiary_or_recipient,
            'requirements' => $this->requirements ?? [],
            'status' => $this->status,
            'draftCurrentStep' => $this->draft_current_step,
            'releaseDate' => $this->release_date?->toDateString(),
            'releaseReferenceNumber' => $this->release_reference_number,
            'rejectionReason' => $this->rejection_reason,
            'cancellationReason' => $this->cancellation_reason,
            'remarks' => $this->remarks,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'createdBy' => $this->created_by,
        ];
    }
}
