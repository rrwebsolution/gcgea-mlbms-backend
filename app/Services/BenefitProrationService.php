<?php

namespace App\Services;

use App\Models\BenefitType;
use App\Models\BenefitTypeProrationTier;
use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\Member;
use App\Models\SystemSetting;

/**
 * Prorates a BenefitType's payout by the member's paid contribution-months,
 * per GCGEA Board Resolution No. 24-2026 (Table 1 — Core Benefits, counted
 * against monthly dues; Table 2 — Cash Pabaon Program, counted against
 * Cash Pabaon paid via either the payroll Pabaon deduction or a direct
 * "Cash Pabaon" contribution entry, and whose 100% base escalates by fiscal
 * year).
 *
 * A BenefitType with no proration_tiers rows is unaffected — callers should
 * fall back to its flat maximum_amount / manual entry, same as before this
 * service existed.
 */
class BenefitProrationService
{
    public const RESOLUTION_27_EFFECTIVE_MEMBERSHIP_DATE = '2026-09-01';

    /**
     * Distinct paid periods on record for the member, against the ledger the
     * benefit type prorates against.
     */
    public function monthsPaid(Member $member, string $countBasis): int
    {
        $settings = SystemSetting::query()->where('section', 'contribution')->value('value') ?? [];

        if ($countBasis === 'pabaon') {
            $requiredPabaon = (float) ($settings['defaultCashPabaonContribution'] ?? 200);

            // Cash Pabaon is paid through either channel — a payroll Pabaon
            // deduction, or a direct "Cash Pabaon" contribution entry — so a
            // period counts as paid if it's posted on either ledger.
            $deductionPeriods = Deduction::query()
                ->where('member_id', $member->id)
                ->where('status', 'Posted')
                ->whereHas('deductionType', fn ($q) => $q->where('code', 'pabaon'))
                ->pluck('period');

            $contributionPeriods = Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'Posted')
                ->where('contribution_type', 'Cash Pabaon')
                ->where('amount', '>=', $requiredPabaon)
                ->pluck('contribution_period');

            return $deductionPeriods->merge($contributionPeriods)->unique()->count();
        }

        $requiredDues = (float) ($settings['defaultMonthlyContribution'] ?? 100);

        return Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'Posted')
            ->where('contribution_type', 'Monthly Dues')
            ->where('amount', '>=', $requiredDues)
            ->distinct('contribution_period')
            ->count('contribution_period');
    }

    public function tierFor(BenefitType $benefitType, Member $member, int $months): ?BenefitTypeProrationTier
    {
        $scope = $this->membershipScope($benefitType, $member);

        return $benefitType->prorationTiers->first(
            fn (BenefitTypeProrationTier $tier) => in_array($tier->membership_scope, ['all', $scope], true)
                && $months >= $tier->min_months
                && ($tier->max_months === null || $months <= $tier->max_months)
        );
    }

    public function membershipScope(BenefitType $benefitType, Member $member): string
    {
        if ($benefitType->proration_basis !== 'pabaon') {
            return 'all';
        }

        return $member->membership_date?->gte(self::RESOLUTION_27_EFFECTIVE_MEMBERSHIP_DATE) ? 'new' : 'legacy';
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
        $tier = $this->tierFor($benefitType, $member, $months);
        $base = $this->baseAmount($benefitType, $fiscalYear);
        $amount = $tier ? round($base * ((float) $tier->percentage) / 100, 2) : 0.0;

        return ['amount' => $amount, 'monthsPaid' => $months, 'tier' => $tier, 'membershipScope' => $this->membershipScope($benefitType, $member)];
    }
}
