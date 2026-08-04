<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'memberNumber' => $this->member_number,
            'employeeNumber' => $this->employee_number,
            'surname' => $this->surname,
            'firstName' => $this->first_name,
            'middleName' => $this->middle_name,
            'suffix' => $this->suffix,
            'fullName' => $this->full_name,
            'sex' => $this->sex,
            'birthdate' => $this->birthdate?->toDateString(),
            'civilStatus' => $this->civil_status,
            'permanentAddress' => $this->permanent_address,
            'cellphoneNumber' => $this->cellphone_number,
            'email' => $this->email,
            'nameOfSpouse' => $this->name_of_spouse,
            'profilePhotoUrl' => $this->profile_photo_url,

            'officeId' => (string) $this->office_id,
            'officeName' => $this->office?->name,
            'position' => $this->position,
            'dateOfRegularAppointment' => $this->date_of_regular_appointment?->toDateString(),
            'employmentStatus' => $this->employment_status,

            'membershipType' => $this->membership_type,
            'membershipDate' => $this->membership_date?->toDateString(),
            'membershipStatus' => $this->membership_status,
            'netPay' => $this->net_pay === null ? null : (float) $this->net_pay,
            'retireeStatus' => $this->retiree_status,
            'remarks' => $this->remarks,

            'membershipFeePayment' => $this->membershipFeePayment ? [
                'referenceNumber' => $this->membershipFeePayment->reference_number,
                'amount' => (float) $this->membershipFeePayment->amount,
                'paymentDate' => $this->membershipFeePayment->payment_date?->toDateString(),
                'paymentMethod' => $this->membershipFeePayment->payment_method,
                'receivedBy' => $this->membershipFeePayment->received_by,
                'status' => $this->membershipFeePayment->status,
            ] : null,

            'beneficiaries' => BeneficiaryResource::collection($this->beneficiaries),
            'documents' => MemberDocumentResource::collection($this->documents),

            'isArchived' => $this->is_archived,
            'archivedAt' => $this->archived_at?->toIso8601String(),
            'archivedReason' => $this->archived_reason,

            'importedFromBatchId' => $this->imported_from_batch_id ? (string) $this->imported_from_batch_id : null,

            'isDraft' => $this->is_draft,
            'draftReferenceNo' => $this->draft_reference_no,
            'draftCompletionPercentage' => $this->draft_completion_percentage,
            'draftCurrentStep' => $this->draft_current_step,

            // Registration approval progress lives on approval_instances, not
            // membership_status (an independent, encoder-owned field) — see
            // ApprovalWorkflowService's per-subject notes.
            'approvalStatus' => $this->whenLoaded('approvalInstance', fn () => $this->approvalInstance?->status),
            'registrationStatus' => $this->registration_status,
            'approvalSource' => $this->approval_source,
            'submittedAt' => $this->submitted_at?->toIso8601String(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'approvedByUserId' => $this->approved_by_user_id ? (string) $this->approved_by_user_id : null,

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'createdBy' => $this->created_by,
        ];
    }
}
