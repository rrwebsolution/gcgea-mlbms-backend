<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
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
            'loanTypeId' => (string) $this->loan_type_id,
            'loanTypeName' => $this->loanType?->name,
            'requestedAmount' => (float) $this->requested_amount,
            'approvedAmount' => $this->approved_amount !== null ? (float) $this->approved_amount : null,
            'termMonths' => $this->term_months,
            'interestRate' => (float) $this->interest_rate,
            'processingFee' => (float) $this->processing_fee,
            'purpose' => $this->purpose,
            'paymentMethod' => $this->payment_method,
            'firstDueDate' => $this->first_due_date?->toDateString(),
            'maturityDate' => $this->maturity_date?->toDateString(),

            'principal' => (float) $this->principal,
            'totalInterest' => (float) $this->total_interest,
            'netProceeds' => (float) $this->net_proceeds,
            'totalAmountPayable' => (float) $this->total_amount_payable,
            'monthlyAmortization' => (float) $this->monthly_amortization,
            'outstandingBalance' => (float) $this->outstanding_balance,

            'status' => $this->status,
            'draftCurrentStep' => $this->draft_current_step,
            'assignedOfficer' => $this->assigned_officer,

            'eligibility' => $this->eligibility ?? [],
            'eligibilityOverridden' => $this->eligibility_overridden,
            'eligibilityOverrideReason' => $this->eligibility_override_reason,
            'requirements' => $this->requirements ?? [],

            'releaseDate' => $this->release_date?->toDateString(),
            'releaseReferenceNumber' => $this->release_reference_number,
            'releaseMethod' => $this->release_method,
            'actualReleasedAmount' => $this->actual_released_amount !== null ? (float) $this->actual_released_amount : null,
            'releaseRemarks' => $this->release_remarks,

            'rejectionReason' => $this->rejection_reason,
            'cancellationReason' => $this->cancellation_reason,

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'createdBy' => $this->created_by,
        ];
    }
}
