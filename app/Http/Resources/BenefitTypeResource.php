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
            'prorationBasis' => $this->proration_basis,
            'prorationTiers' => $this->whenLoaded('prorationTiers', fn () => $this->prorationTiers->map(fn ($tier) => [
                'id' => (string) $tier->id,
                'membershipScope' => $tier->membership_scope,
                'minMonths' => (int) $tier->min_months,
                'maxMonths' => $tier->max_months === null ? null : (int) $tier->max_months,
                'percentage' => (float) $tier->percentage,
            ]), []),
            'fyAmounts' => $this->whenLoaded('fyAmounts', fn () => $this->fyAmounts->map(fn ($fy) => [
                'id' => (string) $fy->id,
                'fiscalYear' => $fy->fiscal_year === null ? null : (int) $fy->fiscal_year,
                'baseAmount' => (float) $fy->base_amount,
            ]), []),
            'eligibilityRequirements' => (string) $this->eligibility_requirements,
            'requiredMembershipMonths' => (int) $this->required_membership_months,
            'frequencyLimit' => (string) $this->frequency_limit,
            'requiredDocuments' => $this->required_documents ?? [],
            'approvalRequired' => (bool) $this->approval_required,
            'status' => $this->status,
        ];
    }
}
