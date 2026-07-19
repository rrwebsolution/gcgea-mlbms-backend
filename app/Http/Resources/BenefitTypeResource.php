<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a BenefitType model into the exact `BenefitType` shape the frontend
 * expects (see src/types/benefit.ts) — camelCase keys, string id, decimals as
 * numbers — so the frontend service layer needs no transform step.
 */
class BenefitTypeResource extends JsonResource
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
            'defaultAmount' => (float) $this->default_amount,
            'maximumAmount' => (float) $this->maximum_amount,
            'eligibilityRequirements' => (string) $this->eligibility_requirements,
            'requiredMembershipMonths' => (int) $this->required_membership_months,
            'frequencyLimit' => (string) $this->frequency_limit,
            'requiredDocuments' => $this->required_documents ?? [],
            'approvalRequired' => (bool) $this->approval_required,
            'status' => $this->status,
        ];
    }
}
