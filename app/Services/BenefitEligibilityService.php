<?php

namespace App\Services;

use App\Models\BenefitApplication;
use App\Models\BenefitType;
use App\Models\Member;
use Carbon\Carbon;

/**
 * Ports evaluateBenefitEligibility() from src/utils/eligibility.ts 1:1 —
 * including the fragile frequency-limit string-matching (checks #3), which is
 * intentional: BenefitType.frequency_limit is deliberately a free-text field,
 * not a structured one. Do not "fix" this here.
 */
class BenefitEligibilityService
{
    /**
     * @return array<int, array{label: string, passed: bool, detail: string}>
     */
    public function evaluate(Member $member, BenefitType $benefitType, float $requestedAmount): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'Active Member',
            'passed' => $member->membership_status === 'Active',
            'detail' => $member->membership_status === 'Active'
                ? 'Member is in active standing.'
                : "Member status is {$member->membership_status}, not Active.",
        ];

        $membershipMonths = $this->monthsBetween(Carbon::parse($member->membership_date), now());
        $checks[] = [
            'label' => 'Membership Duration',
            'passed' => $membershipMonths >= $benefitType->required_membership_months,
            'detail' => "Member for {$membershipMonths} month(s); requires {$benefitType->required_membership_months}.",
        ];

        $priorBenefitCount = BenefitApplication::where('member_id', $member->id)
            ->where('benefit_type_id', $benefitType->id)
            ->whereIn('status', ['Released', 'Completed'])
            ->count();
        $mentionsYear = str_contains(strtolower($benefitType->frequency_limit), 'year');
        $frequencyPassed = $priorBenefitCount === 0 || ($mentionsYear ? $priorBenefitCount < 2 : $priorBenefitCount === 0);
        $checks[] = [
            'label' => 'Frequency Limit',
            'passed' => $frequencyPassed,
            'detail' => "{$priorBenefitCount} prior release(s) of this benefit type; limit \"{$benefitType->frequency_limit}\".",
        ];

        $hasPendingApplication = BenefitApplication::where('member_id', $member->id)
            ->where('benefit_type_id', $benefitType->id)
            ->whereIn('status', ['Draft', 'Submitted', 'Under Review', 'For Approval'])
            ->exists();
        $checks[] = [
            'label' => 'No Duplicate Pending Application',
            'passed' => ! $hasPendingApplication,
            'detail' => $hasPendingApplication
                ? 'Member already has a pending application for this benefit type.'
                : 'No pending application of this type.',
        ];

        $profileComplete = $member->isProfileComplete();
        $checks[] = [
            'label' => 'Profile Complete',
            'passed' => $profileComplete,
            'detail' => $profileComplete ? 'Member profile is complete.' : 'Member profile is missing required information.',
        ];

        $checks[] = [
            'label' => 'Requested Amount Within Maximum',
            'passed' => $requestedAmount <= $benefitType->maximum_amount,
            'detail' => "Requested ₱{$requestedAmount}; maximum ₱{$benefitType->maximum_amount}.",
        ];

        return $checks;
    }

    /**
     * @param  array<int, array{label: string, passed: bool, detail: string}>  $checks
     */
    public function resultFor(array $checks): string
    {
        $criticalLabels = ['Active Member', 'No Duplicate Pending Application'];
        $failed = array_filter($checks, fn ($c) => ! $c['passed']);

        if (array_intersect(array_column($failed, 'label'), $criticalLabels) !== []) {
            return 'Not Eligible';
        }

        return $failed !== [] ? 'Eligible with Warning' : 'Eligible';
    }

    private function monthsBetween(Carbon $start, Carbon $end): int
    {
        return ($end->year - $start->year) * 12 + ($end->month - $start->month);
    }
}
