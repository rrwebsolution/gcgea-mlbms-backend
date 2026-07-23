<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Loan;
use App\Models\LoanSetting;
use App\Models\LoanType;
use App\Models\Member;
use Carbon\Carbon;

/**
 * Ports evaluateLoanEligibility() from src/utils/eligibility.ts — extended
 * with the global minimum-membership-months floor (LoanSetting) and
 * dues/Cash-Pabaon standing checks. Check methods are broken out so
 * ReloanEligibilityService can reuse the member-level checks instead of
 * duplicating them.
 */
class LoanEligibilityService
{
    /**
     * @return array<int, array{label: string, passed: bool, detail: string}>
     */
    public function evaluate(Member $member, LoanType $loanType, float $requestedAmount, int $termMonths): array
    {
        return [
            ...$this->memberStandingChecks($member, $loanType),
            $this->existingActiveLoanCheck($member, $loanType),
            $this->overdueLoanCheck($member),
            $this->amountOrNetPayCheck($member, $loanType, $requestedAmount),
            $this->termCheck($loanType, $termMonths),
            $this->profileCompleteCheck($member),
        ];
    }

    /**
     * The checks that only depend on the member (not on a specific loan
     * type/amount/term) — shared by new-loan and reloan eligibility.
     *
     * @return array<int, array{label: string, passed: bool, detail: string}>
     */
    public function memberStandingChecks(Member $member, ?LoanType $loanType = null, bool $forReloan = false): array
    {
        $settings = LoanSetting::current();

        return [
            $this->registrationApprovedCheck($member),
            $this->activeStatusCheck($member),
            $this->membershipDurationCheck($member, $loanType),
            $forReloan && ! $settings->apply_contribution_rule_to_reloan
                ? [
                    'label' => 'Fully Paid Monthly Dues',
                    'passed' => true,
                    'detail' => 'Paid Monthly Dues eligibility is not required for reloan under the current settings.',
                ]
                : $this->contributionDurationCheck($member, $loanType),
            $this->contributionStandingCheck($member, 'Monthly Dues'),
        ];
    }

    /**
     * Most members in this system (payroll/manual imports, legacy data
     * migrations) are created directly at membership_status='Active' and
     * never routed through the registration draft-submission workflow, so
     * they have no approval_instances row at all — that's not the same as
     * "not yet approved". Only a member still mid-review (membership_status
     * literally 'Pending', or an approval_instances row that exists but
     * isn't 'approved' — e.g. pending/rejected/returned) fails this check.
     */
    public function registrationApprovedCheck(Member $member): array
    {
        $instanceStatus = $member->approvalInstance?->status;
        $approved = $instanceStatus !== null ? $instanceStatus === 'approved' : $member->membership_status !== 'Pending';

        return [
            'label' => 'Registration Approved',
            'passed' => $approved,
            'detail' => $approved
                ? 'Member registration has been approved.'
                : 'Member registration is not yet approved.',
        ];
    }

    public function activeStatusCheck(Member $member): array
    {
        return [
            'label' => 'Active Member',
            'passed' => $member->membership_status === 'Active',
            'detail' => $member->membership_status === 'Active'
                ? 'Member is in active standing.'
                : "Member status is {$member->membership_status}, not Active.",
        ];
    }

    /**
     * @return array{label: string, passed: bool, detail: string, completed_months: int, required_months: int, eligible_on: string}
     */
    public function membershipDurationCheck(Member $member, ?LoanType $loanType = null): array
    {
        $required = max(
            $loanType?->required_membership_months ?? 0,
            LoanSetting::current()->minimum_membership_months
        );

        $membershipDate = Carbon::parse($member->membership_date);
        $completedMonths = $this->monthsBetween($membershipDate, now());
        $eligibleOn = $membershipDate->copy()->addMonths($required);

        return [
            'label' => 'Minimum Membership Duration Met',
            'passed' => $completedMonths >= $required,
            'detail' => "Member for {$completedMonths} month(s); requires {$required}.",
            'completed_months' => $completedMonths,
            'required_months' => $required,
            'eligible_on' => $eligibleOn->toDateString(),
        ];
    }

    public function contributionDurationCheck(Member $member, ?LoanType $loanType = null): array
    {
        $settings = LoanSetting::current();
        $required = max(
            $loanType?->required_contribution_months ?? 0,
            $settings->minimum_paid_contribution_months
        );
        $requiredAmount = (float) $settings->required_monthly_dues_amount;

        if (! $settings->require_paid_contributions) {
            return [
                'label' => 'Fully Paid Monthly Dues',
                'passed' => true,
                'detail' => 'Paid Monthly Dues eligibility is disabled in Loan Settings.',
                'paid_months' => 0,
                'consecutive_paid_months' => 0,
                'required_months' => $required,
                'required_amount' => $requiredAmount,
                'latest_paid_period' => null,
                'missing_periods' => [],
            ];
        }

        $periods = Contribution::where('member_id', $member->id)
            ->where('contribution_type', 'Monthly Dues')
            ->where('status', 'Posted')
            ->where('amount', '>=', $requiredAmount)
            ->pluck('contribution_period')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $paidMonths = $periods->count();
        $consecutiveMonths = $this->latestConsecutiveMonthCount($periods->all());
        $verifiedMonths = $settings->require_consecutive_contribution_months ? $consecutiveMonths : $paidMonths;
        $passed = $verifiedMonths >= $required;
        $latestPeriod = $periods->last();
        $missingPeriods = $this->missingRequiredPeriods($periods->all(), $required, $latestPeriod);

        if ($passed) {
            $detail = "{$verifiedMonths} fully paid Monthly Dues month(s) verified; requires {$required}.";
        } elseif ($paidMonths === 0) {
            $detail = 'This member cannot apply for a loan because no fully paid Monthly Dues contributions are recorded.';
        } else {
            $detail = "Only {$verifiedMonths} of required {$required} fully paid Monthly Dues months were verified.";
        }

        return [
            'label' => 'Fully Paid Monthly Dues',
            'passed' => $passed,
            'detail' => $detail,
            'paid_months' => $paidMonths,
            'consecutive_paid_months' => $consecutiveMonths,
            'required_months' => $required,
            'required_amount' => $requiredAmount,
            'latest_paid_period' => $latestPeriod,
            'missing_periods' => $missingPeriods,
        ];
    }

    /**
     * "Current" is defined pragmatically as a Posted contribution of the
     * given type on record for the current or immediately preceding
     * contribution_period ("YYYY-MM") — there's no arrears/ledger system to
     * compute true delinquency against.
     */
    public function contributionStandingCheck(Member $member, string $type): array
    {
        $currentPeriod = now()->format('Y-m');
        $priorPeriod = now()->subMonthNoOverflow()->format('Y-m');

        $current = Contribution::where('member_id', $member->id)
            ->where('contribution_type', $type)
            ->where('status', 'Posted')
            ->whereIn('contribution_period', [$currentPeriod, $priorPeriod])
            ->exists();

        $label = $type === 'Cash Pabaon' ? 'Cash Pabaon Current' : 'Monthly Dues Current';

        return [
            'label' => $label,
            'passed' => $current,
            'detail' => $current
                ? "{$type} contribution is current."
                : "No {$type} contribution on record for {$priorPeriod} or {$currentPeriod}.",
        ];
    }

    public function existingActiveLoanCheck(Member $member, LoanType $loanType): array
    {
        $hasBlockingActiveLoan = ! $loanType->allow_existing_active_loan
            && Loan::where('member_id', $member->id)->whereIn('status', ['Active', 'Overdue', 'Released'])->exists();

        return [
            'label' => 'No Existing Active Loan',
            'passed' => ! $hasBlockingActiveLoan,
            'detail' => $hasBlockingActiveLoan
                ? 'Member already has an active/released loan and this loan type does not allow concurrent loans.'
                : 'No disqualifying active loan.',
        ];
    }

    public function overdueLoanCheck(Member $member): array
    {
        $hasOverdueLoan = Loan::where('member_id', $member->id)->where('status', 'Overdue')->exists();

        return [
            'label' => 'No Overdue Loan',
            'passed' => ! $hasOverdueLoan,
            'detail' => $hasOverdueLoan ? 'Member has an overdue loan on record.' : 'No overdue loans.',
        ];
    }

    public function amountOrNetPayCheck(Member $member, LoanType $loanType, float $requestedAmount): array
    {
        if ($loanType->incomeBrackets->isNotEmpty()) {
            $hasNetPay = $member->net_pay !== null;

            return [
                'label' => 'Net Pay On File',
                'passed' => $hasNetPay,
                'detail' => $hasNetPay
                    ? "Member's monthly net pay (₱{$member->net_pay}) is on file for bracket lookup."
                    : 'This loan type requires the member\'s monthly net take-home pay to determine the loanable amount.',
            ];
        }

        $amountWithinRange = $requestedAmount >= $loanType->min_amount && $requestedAmount <= $loanType->max_amount;

        return [
            'label' => 'Requested Amount Within Range',
            'passed' => $amountWithinRange,
            'detail' => "Requested ₱{$requestedAmount}; allowed range ₱{$loanType->min_amount}–₱{$loanType->max_amount}.",
        ];
    }

    public function termCheck(LoanType $loanType, int $termMonths): array
    {
        return [
            'label' => 'Term Within Maximum',
            'passed' => $termMonths <= $loanType->max_term_months,
            'detail' => "Requested {$termMonths} month(s); maximum {$loanType->max_term_months}.",
        ];
    }

    public function profileCompleteCheck(Member $member): array
    {
        $profileComplete = $member->isProfileComplete();

        return [
            'label' => 'Profile Complete',
            'passed' => $profileComplete,
            'detail' => $profileComplete ? 'Member profile is complete.' : 'Member profile is missing required information.',
        ];
    }

    /**
     * @param  array<int, array{label: string, passed: bool, detail: string}>  $checks
     */
    public function resultFor(array $checks): string
    {
        $criticalLabels = [
            'Registration Approved',
            'Active Member',
            'Minimum Membership Duration Met',
            'Fully Paid Monthly Dues',
            'Monthly Dues Current',
            'No Overdue Loan',
        ];
        $failed = array_filter($checks, fn ($c) => ! $c['passed']);

        if (array_intersect(array_column($failed, 'label'), $criticalLabels) !== []) {
            return 'Not Eligible';
        }

        return $failed !== [] ? 'Eligible with Warning' : 'Eligible';
    }

    /**
     * @param  array<int, array{label: string, passed: bool, detail: string}>  $checks
     * @return array<int, string>
     */
    public function getIneligibilityReasons(array $checks): array
    {
        return array_values(array_column(array_filter($checks, fn ($c) => ! $c['passed']), 'detail'));
    }

    private function monthsBetween(Carbon $start, Carbon $end): int
    {
        return ($end->year - $start->year) * 12 + ($end->month - $start->month);
    }

    /**
     * Counts the consecutive run ending at the member's latest fully paid
     * Monthly Dues period.
     *
     * @param  array<int, string>  $periods
     */
    private function latestConsecutiveMonthCount(array $periods): int
    {
        if ($periods === []) {
            return 0;
        }

        rsort($periods);
        $count = 1;
        $expected = Carbon::createFromFormat('Y-m', $periods[0])->startOfMonth()->subMonthNoOverflow();

        foreach (array_slice($periods, 1) as $period) {
            $date = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
            if (! $date->equalTo($expected)) {
                break;
            }
            $count++;
            $expected->subMonthNoOverflow();
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $periods
     * @return array<int, string>
     */
    private function missingRequiredPeriods(array $periods, int $required, ?string $latestPeriod): array
    {
        if ($required < 1 || $latestPeriod === null) {
            return [];
        }

        $paid = array_flip($periods);
        $cursor = Carbon::createFromFormat('Y-m', $latestPeriod)->startOfMonth();
        $missing = [];

        for ($index = 0; $index < $required; $index++) {
            $period = $cursor->format('Y-m');
            if (! isset($paid[$period])) {
                $missing[] = $period;
            }
            $cursor->subMonthNoOverflow();
        }

        return $missing;
    }
}
