<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a LoanType model into the exact `LoanType` shape the frontend expects
 * (see src/types/loan.ts) — camelCase keys, string id, decimals as numbers —
 * so the frontend service layer needs no transform step.
 */
class LoanTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => (string) $this->description,
            'minAmount' => (float) $this->min_amount,
            'maxAmount' => (float) $this->max_amount,
            'defaultInterestRate' => (float) $this->default_interest_rate,
            'interestMethod' => $this->interest_method,
            'processingFee' => (float) $this->processing_fee,
            'maxTermMonths' => (int) $this->max_term_months,
            'requiredMembershipMonths' => (int) $this->required_membership_months,
            'requiredContributionMonths' => (int) $this->required_contribution_months,
            'allowExistingActiveLoan' => (bool) $this->allow_existing_active_loan,
            'status' => $this->status,
        ];
    }
}
