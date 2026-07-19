<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'paymentReferenceNumber' => $this->payment_reference_number,
            'memberId' => (string) $this->member_id,
            'memberName' => $this->member?->full_name,
            'loanApplicationId' => (string) $this->loan_application_id,
            'loanApplicationNumber' => $this->loan?->application_number,
            'paymentDate' => $this->payment_date?->toDateString(),
            'amountPaid' => (float) $this->amount_paid,
            'principalPortion' => (float) $this->principal_portion,
            'interestPortion' => (float) $this->interest_portion,
            'penalty' => (float) $this->penalty,
            'paymentMethod' => $this->payment_method,
            'payrollReference' => $this->payroll_reference,
            'officialReceiptNumber' => $this->official_receipt_number,
            'receivedBy' => $this->received_by,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'voidReason' => $this->void_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
