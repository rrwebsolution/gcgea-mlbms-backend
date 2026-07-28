<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisbursementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'referenceNumber' => $this->reference_number,
            'annualBudgetId' => (string) $this->annual_budget_id,
            'fiscalYear' => $this->annualBudget?->fiscal_year,
            'annualBudgetItemId' => (string) $this->annual_budget_item_id,
            'accountTitle' => $this->budgetItem?->account_title,
            'disbursementDate' => $this->disbursement_date?->toDateString(),
            'payee' => $this->payee,
            'amount' => (float) $this->amount,
            'paymentMethod' => $this->payment_method,
            'paymentReference' => $this->payment_reference,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'preparedBy' => $this->prepared_by,
            'approvedBy' => $this->approved_by,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'paidBy' => $this->paid_by,
            'paidAt' => $this->paid_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'voidReason' => $this->void_reason,
            'currentStageLabel' => $this->approvalInstance?->currentStage?->label,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
