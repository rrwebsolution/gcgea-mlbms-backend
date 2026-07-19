<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Loan;
use App\Models\LoanType;
use App\Models\Member;
use Carbon\Carbon;

/** Ports evaluateLoanEligibility() from src/utils/eligibility.ts 1:1. */
class LoanEligibilityService
{
    /**
     * @return array<int, array{label: string, passed: bool, detail: string}>
     */
    public function evaluate(Member $member, LoanType $loanType, float $requestedAmount, int $termMonths): array
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
            'passed' => $membershipMonths >= $loanType->required_membership_months,
            'detail' => "Member for {$membershipMonths} month(s); requires {$loanType->required_membership_months}.",
        ];

        $contributionMonths = Contribution::where('member_id', $member->id)
            ->distinct('contribution_period')
            ->count('contribution_period');
        $checks[] = [
            'label' => 'Contribution Duration',
            'passed' => $contributionMonths >= $loanType->required_contribution_months,
            'detail' => "{$contributionMonths} contribution period(s) on record; requires {$loanType->required_contribution_months}.",
        ];

        $hasBlockingActiveLoan = ! $loanType->allow_existing_active_loan
            && Loan::where('member_id', $member->id)->whereIn('status', ['Active', 'Overdue', 'Released'])->exists();
        $checks[] = [
            'label' => 'No Existing Active Loan',
            'passed' => ! $hasBlockingActiveLoan,
            'detail' => $hasBlockingActiveLoan
                ? 'Member already has an active/released loan and this loan type does not allow concurrent loans.'
                : 'No disqualifying active loan.',
        ];

        $hasOverdueLoan = Loan::where('member_id', $member->id)->where('status', 'Overdue')->exists();
        $checks[] = [
            'label' => 'No Overdue Loan',
            'passed' => ! $hasOverdueLoan,
            'detail' => $hasOverdueLoan ? 'Member has an overdue loan on record.' : 'No overdue loans.',
        ];

        $amountWithinRange = $requestedAmount >= $loanType->min_amount && $requestedAmount <= $loanType->max_amount;
        $checks[] = [
            'label' => 'Requested Amount Within Range',
            'passed' => $amountWithinRange,
            'detail' => "Requested ₱{$requestedAmount}; allowed range ₱{$loanType->min_amount}–₱{$loanType->max_amount}.",
        ];

        $checks[] = [
            'label' => 'Term Within Maximum',
            'passed' => $termMonths <= $loanType->max_term_months,
            'detail' => "Requested {$termMonths} month(s); maximum {$loanType->max_term_months}.",
        ];

        $profileComplete = $member->isProfileComplete();
        $checks[] = [
            'label' => 'Profile Complete',
            'passed' => $profileComplete,
            'detail' => $profileComplete ? 'Member profile is complete.' : 'Member profile is missing required information.',
        ];

        return $checks;
    }

    private function monthsBetween(Carbon $start, Carbon $end): int
    {
        return ($end->year - $start->year) * 12 + ($end->month - $start->month);
    }
}
