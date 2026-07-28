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
        $outstandingBalance = max(
            0,
            round((float) $this->principal_balance + (float) $this->interest_balance, 2)
        );

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
            'outstandingBalance' => $outstandingBalance,

            'status' => $this->status,
            'draftCurrentStep' => $this->draft_current_step,
            'assignedOfficer' => $this->legacy_source_name
                ? ($this->assigned_officer && $this->assigned_officer !== 'Legacy Loan Import'
                    ? $this->assigned_officer
                    : 'Pre-System Loan Officer')
                : ($this->assigned_officer ?: 'Unassigned'),

            'applicationType' => $this->application_type,
            'previousLoanId' => $this->previous_loan_id !== null ? (string) $this->previous_loan_id : null,
            'previousLoanReference' => $this->previousLoan?->application_number,
            'rootLoanId' => $this->root_loan_id !== null ? (string) $this->root_loan_id : null,
            'reloanSequence' => $this->reloan_sequence,
            'currentNetTakeHomePay' => $this->current_net_take_home_pay !== null ? (float) $this->current_net_take_home_pay : null,

            'eligibility' => $this->eligibility ?? [],
            'eligibilityOverridden' => $this->eligibility_overridden,
            'eligibilityOverrideReason' => $this->eligibility_override_reason,
            'reloanPolicySnapshot' => $this->reloan_policy_snapshot,
            'previousObligationAmount' => $this->previous_obligation_amount !== null ? (float) $this->previous_obligation_amount : null,
            'previousObligationSettlementMethod' => $this->previous_obligation_settlement_method,
            'previousObligationSettledAt' => $this->previous_obligation_settled_at?->toIso8601String(),
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
