<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanSetting;
use App\Models\LoanType;
use App\Models\Member;

/**
 * Reloan-specific eligibility, layered on top of LoanEligibilityService's
 * member-standing checks. The previous loan's status must satisfy the
 * configured Reloan Policy (LoanSetting) — this phase only functionally
 * supports the "previous loan fully paid" path; "reloan while active" is
 * schema-ready (previous_obligation_* columns) but always forces
 * previous_obligation_settlement_method = full_payment_required, i.e. no
 * deduct-from-proceeds settlement yet.
 */
class ReloanEligibilityService
{
    public function __construct(private readonly LoanEligibilityService $loanEligibility) {}

    /**
     * @return array{checks: array<int, array{label: string, passed: bool, detail: string}>, verdict: string, previousObligation: array{amount: float, settlementMethod: ?string}}
     */
    public function canCreateReloan(Loan $previousLoan, Member $member, LoanType $loanType, float $requestedAmount, int $termMonths): array
    {
        $policy = LoanSetting::current();

        $checks = [
            ...$this->loanEligibility->memberStandingChecks($member, $loanType, true),
            $this->ownershipCheck($previousLoan, $member),
            $this->previousLoanStatusCheck($previousLoan, $policy),
            ...$this->paidThresholdChecks($previousLoan, $policy),
            $this->noPendingReloanCheck($previousLoan),
            $this->maxConcurrentActiveLoansCheck($member, $previousLoan, $policy),
            $this->loanEligibility->amountOrNetPayCheck($member, $loanType, $requestedAmount),
            $this->loanEligibility->termCheck($loanType, $termMonths),
            $this->loanEligibility->profileCompleteCheck($member),
        ];

        $previousObligation = $this->previousObligation($previousLoan, $policy);
        if ($previousObligation['amount'] > 0 && ! $policy->reloan_allow_while_active) {
            $checks[] = [
                'label' => 'Previous Obligation Settled',
                'passed' => false,
                'detail' => "Previous loan has an outstanding balance of ₱{$previousObligation['amount']} that must be settled before this reloan can be released.",
            ];
        }

        return [
            'checks' => $checks,
            'verdict' => $this->resultFor($checks),
            'previousObligation' => $previousObligation,
        ];
    }

    private function ownershipCheck(Loan $previousLoan, Member $member): array
    {
        $owned = $previousLoan->member_id === $member->id;

        return [
            'label' => 'Previous Loan Belongs to Member',
            'passed' => $owned,
            'detail' => $owned ? 'Previous loan belongs to the selected member.' : 'The selected previous loan does not belong to this member.',
        ];
    }

    private function previousLoanStatusCheck(Loan $previousLoan, LoanSetting $policy): array
    {
        if (! $policy->reloan_enabled) {
            return ['label' => 'Reloan Enabled', 'passed' => false, 'detail' => 'Reloans are currently disabled by policy.'];
        }

        $eligibleStatuses = [];
        if ($policy->reloan_allow_after_fully_paid) {
            $eligibleStatuses[] = 'Fully Paid';
        }
        if ($policy->reloan_allow_while_active) {
            $eligibleStatuses[] = 'Active';
            $eligibleStatuses[] = 'Released';
        }

        $passed = in_array($previousLoan->status, $eligibleStatuses, true);

        return [
            'label' => 'Previous Loan Status Eligible',
            'passed' => $passed,
            'detail' => $passed
                ? "Previous loan status \"{$previousLoan->status}\" is eligible for reloan under the current policy."
                : "Previous loan status is \"{$previousLoan->status}\"; current policy requires: ".implode(', ', $eligibleStatuses ?: ['none — reloan disabled']).'.',
        ];
    }

    /**
     * @return array<int, array{label: string, passed: bool, detail: string}>
     */
    private function paidThresholdChecks(Loan $previousLoan, LoanSetting $policy): array
    {
        $checks = [];

        if ($policy->reloan_require_no_overdue) {
            $noOverdue = $previousLoan->status !== 'Overdue';
            $checks[] = [
                'label' => 'No Overdue Installments',
                'passed' => $noOverdue,
                'detail' => $noOverdue ? 'No overdue installments on the previous loan.' : 'The previous loan has overdue installments.',
            ];
        }

        if ($policy->reloan_require_no_penalty) {
            $assessedPenalty = (float) $previousLoan->schedule()->sum('penalty');
            $paidPenalty = (float) $previousLoan->payments()->where('status', 'Posted')->sum('penalty');
            $noPenalty = max(0, round($assessedPenalty - $paidPenalty, 2)) <= 0.01;
            $checks[] = [
                'label' => 'No Unpaid Penalty',
                'passed' => $noPenalty,
                'detail' => $noPenalty ? 'No unpaid penalty balance on the previous loan.' : 'The previous loan has an unpaid balance that may include penalties.',
            ];
        }

        if ($policy->reloan_min_paid_installments !== null) {
            $monthlyAmortization = max(0.01, (float) $previousLoan->monthly_amortization);
            $paidInstallments = $previousLoan->payments()
                ->where('status', 'Posted')
                ->get()
                ->groupBy(fn ($payment) => $payment->payment_date?->format('Y-m'))
                ->filter(fn ($payments) => $payments->sum(
                    fn ($payment) => (float) $payment->principal_portion + (float) $payment->interest_portion
                ) >= $monthlyAmortization - 0.01)
                ->count();
            $checks[] = [
                'label' => 'Six-Month Previous Loan Payment',
                'passed' => $paidInstallments >= $policy->reloan_min_paid_installments,
                'detail' => "{$paidInstallments} fully paid loan month(s) recorded; {$policy->reloan_min_paid_installments} required before reloan.",
            ];
        }

        if ($policy->reloan_min_paid_percentage !== null && (float) $previousLoan->principal > 0) {
            $paidPercentage = 100 - ((float) $previousLoan->outstanding_balance / (float) $previousLoan->principal * 100);
            $checks[] = [
                'label' => 'Minimum Paid Percentage Met',
                'passed' => $paidPercentage >= (float) $policy->reloan_min_paid_percentage,
                'detail' => sprintf('%.2f%% paid; requires %.2f%%.', $paidPercentage, (float) $policy->reloan_min_paid_percentage),
            ];
        }

        return $checks;
    }

    private function noPendingReloanCheck(Loan $previousLoan): array
    {
        $hasPending = Loan::where('previous_loan_id', $previousLoan->id)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->exists();

        return [
            'label' => 'No Duplicate Pending Reloan',
            'passed' => ! $hasPending,
            'detail' => $hasPending
                ? 'A reloan application already exists for this loan.'
                : 'No pending reloan application exists for this loan.',
        ];
    }

    private function maxConcurrentActiveLoansCheck(Member $member, Loan $previousLoan, LoanSetting $policy): array
    {
        $activeCount = Loan::where('member_id', $member->id)
            ->where('id', '!=', $previousLoan->id)
            ->whereIn('status', ['Active', 'Overdue', 'Released'])
            ->count();

        $passed = $activeCount < $policy->reloan_max_concurrent_active_loans;

        return [
            'label' => 'Within Maximum Concurrent Active Loans',
            'passed' => $passed,
            'detail' => "Member has {$activeCount} other active loan(s); policy allows a maximum of {$policy->reloan_max_concurrent_active_loans}.",
        ];
    }

    /**
     * @return array{amount: float, settlementMethod: ?string}
     */
    private function previousObligation(Loan $previousLoan, LoanSetting $policy): array
    {
        $amount = (float) $previousLoan->outstanding_balance;

        return [
            'amount' => $amount,
            'settlementMethod' => $amount > 0 ? 'full_payment_required' : null,
        ];
    }

    /**
     * @param  array<int, array{label: string, passed: bool, detail: string}>  $checks
     */
    private function resultFor(array $checks): string
    {
        $criticalLabels = [
            'Registration Approved', 'Active Member', 'Minimum Membership Duration Met',
            'Fully Paid Monthly Dues', 'Monthly Dues Current',
            'Previous Loan Belongs to Member', 'Previous Loan Status Eligible',
            'Six-Month Previous Loan Payment', 'No Duplicate Pending Reloan', 'Previous Obligation Settled',
        ];
        $failed = array_filter($checks, fn ($c) => ! $c['passed']);

        if (array_intersect(array_column($failed, 'label'), $criticalLabels) !== []) {
            return 'Not Eligible';
        }

        return $failed !== [] ? 'Eligible with Warning' : 'Eligible';
    }
}
