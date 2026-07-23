<?php

namespace App\Services;

use App\Models\BenefitType;
use App\Models\BenefitTypeProrationTier;
use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\Member;

/**
 * Prorates a BenefitType's payout by the member's paid contribution-months,
 * per GCGEA Board Resolution No. 24-2026 (Table 1 — Core Benefits, counted
 * against monthly dues; Table 2 — Cash Pabaon Program, counted against the
 * payroll Pabaon deduction, and whose 100% base escalates by fiscal year).
 *
 * A BenefitType with no proration_tiers rows is unaffected — callers should
 * fall back to its flat maximum_amount / manual entry, same as before this
 * service existed.
 */
class BenefitProrationService
{
    /**
     * Distinct paid periods on record for the member, against the ledger the
     * benefit type prorates against.
     */
    public function monthsPaid(Member $member, string $countBasis): int
    {
        if ($countBasis === 'pabaon') {
            return Deduction::query()
                ->where('member_id', $member->id)
                ->where('status', 'Posted')
                ->whereHas('deductionType', fn ($q) => $q->where('code', 'pabaon'))
                ->distinct('period')
                ->count('period');
        }

        return Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'Posted')
            ->distinct('contribution_period')
            ->count('contribution_period');
    }

    public function tierFor(BenefitType $benefitType, int $months): ?BenefitTypeProrationTier
    {
        return $benefitType->prorationTiers->first(
            fn (BenefitTypeProrationTier $tier) => $months >= $tier->min_months
                && ($tier->max_months === null || $months <= $tier->max_months)
        );
    }

    /**
     * The 100%-tier peso base: the benefit type's flat maximum_amount, unless
     * it has fiscal-year-indexed amounts configured (Cash Pabaon Program),
     * in which case the base escalates by year — an unconfigured future year
     * falls back to the highest configured year (the resolution's "and
     * beyond" catch-all).
     */
    public function baseAmount(BenefitType $benefitType, ?int $fiscalYear = null): float
    {
        if ($benefitType->fyAmounts->isEmpty()) {
            return (float) $benefitType->maximum_amount;
        }

        $fiscalYear ??= (int) now()->year;

        $exact = $benefitType->fyAmounts->first(fn ($fy) => $fy->fiscal_year === $fiscalYear);
        if ($exact) {
            return (float) $exact->base_amount;
        }

        $catchAll = $benefitType->fyAmounts->first(fn ($fy) => $fy->fiscal_year === null);
        if ($catchAll) {
            return (float) $catchAll->base_amount;
        }

        $latestConfigured = $benefitType->fyAmounts
            ->filter(fn ($fy) => $fy->fiscal_year !== null)
            ->sortByDesc('fiscal_year')
            ->first();

        return $latestConfigured ? (float) $latestConfigured->base_amount : (float) $benefitType->maximum_amount;
    }

    /**
     * @return array{amount: float|null, monthsPaid: int|null, tier: BenefitTypeProrationTier|null}
     */
    public function computeAmount(BenefitType $benefitType, Member $member, ?int $fiscalYear = null): array
    {
        if ($benefitType->prorationTiers->isEmpty() || ! $benefitType->proration_basis) {
            return ['amount' => null, 'monthsPaid' => null, 'tier' => null];
        }

        $months = $this->monthsPaid($member, $benefitType->proration_basis);
        $tier = $this->tierFor($benefitType, $months);
        $base = $this->baseAmount($benefitType, $fiscalYear);
        $amount = $tier ? round($base * ((float) $tier->percentage) / 100, 2) : 0.0;

        return ['amount' => $amount, 'monthsPaid' => $months, 'tier' => $tier];
    }
}
