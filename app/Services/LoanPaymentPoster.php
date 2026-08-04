<?php

namespace App\Services;

use App\Exceptions\LoanPaymentPostingException;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\LoanSetting;
use Illuminate\Support\Carbon;

/**
 * Single place that knows how to post a payment against a loan: validates
 * postability, splits the payment into principal/interest, updates the
 * loan's balances and status, and creates the LoanPayment record. Extracted
 * from LoanPaymentController::store so both the manual single-payment
 * endpoint and the payroll import commit path share identical behavior.
 *
 * Callers are responsible for `Loan::lockForUpdate()` inside their own
 * DB::transaction — this method doesn't open its own transaction so it
 * composes inside a bulk-import loop without nested-transaction overhead.
 */
class LoanPaymentPoster
{
    private const POSTABLE_STATUSES = ['Released', 'Active', 'Overdue', 'Restructured'];

    private const TOLERANCE = 0.01;

    /**
     * @param  array{amountPaid: float, penalty?: float, principalPortion?: ?float, interestPortion?: ?float}  $data
     *                                                                                                                principalPortion/interestPortion are optional overrides supplied by payroll import (taken straight
     *                                                                                                                from the sheet's own Principal/Interest columns). When omitted, interest-first amortization computes
     *                                                                                                                them — the manual-entry path never supplies overrides, so its behavior is unchanged.
     * @param  array{paymentDate: string, paymentMethod: string, officialReceiptNumber?: ?string, receivedBy: ?string, payrollReference?: ?string, remarks?: ?string}  $meta
     */
    public function post(Loan $loan, array $data, array $meta): LoanPaymentPostResult
    {
        if (! in_array($loan->status, self::POSTABLE_STATUSES, true)) {
            throw new LoanPaymentPostingException('Payments can only be posted to an active or released loan.');
        }

        $amountPaid = round((float) $data['amountPaid'], 2);
        $policy = LoanSetting::current();
        $penalty = array_key_exists('penalty', $data) && $data['penalty'] !== null
            ? round((float) $data['penalty'], 2)
            : $this->automaticPenalty($loan, $meta['paymentDate'], $policy);
        $amountApplied = round($amountPaid - $penalty, 2);
        $principalBalanceBefore = max(0, round((float) $loan->principal_balance, 2));
        $interestBalanceBefore = max(0, round((float) $loan->interest_balance, 2));
        // principal_balance and interest_balance are the authoritative ledger
        // components. Never validate a payment against a stale cached total.
        $outstandingBalance = round($principalBalanceBefore + $interestBalanceBefore, 2);
        $regularInstallment = min($outstandingBalance, max(0, round((float) $loan->monthly_amortization, 2)));

        if ($amountApplied <= 0) {
            throw new LoanPaymentPostingException('The payment amount must be greater than the penalty.');
        }
        if ($amountApplied > $outstandingBalance + self::TOLERANCE) {
            throw new LoanPaymentPostingException('The payment exceeds the outstanding loan balance.');
        }
        if (! $policy->allow_partial_payment && $amountApplied + self::TOLERANCE < $regularInstallment) {
            throw new LoanPaymentPostingException('Partial loan payments are disabled. Please pay at least the regular installment amount.');
        }
        if (! $policy->allow_advance_payment && $amountApplied > $regularInstallment + self::TOLERANCE) {
            throw new LoanPaymentPostingException('Advance loan payments are disabled. Please pay only the regular installment amount.');
        }

        $interestAlreadyPaid = (float) $loan->payments()->where('status', 'Posted')->sum('interest_portion');
        $remainingInterest = max(0, round((float) $loan->total_interest - $interestAlreadyPaid, 2));
        $computedInterestPortion = min($amountApplied, $remainingInterest);
        $computedPrincipalPortion = round($amountApplied - $computedInterestPortion, 2);

        $warnings = [];
        $suppliedPrincipal = $data['principalPortion'] ?? null;
        $suppliedInterest = $data['interestPortion'] ?? null;

        if ($suppliedPrincipal !== null && $suppliedInterest !== null) {
            $principalPortion = round((float) $suppliedPrincipal, 2);
            $interestPortion = round((float) $suppliedInterest, 2);

            if (abs(($principalPortion + $interestPortion) - $amountApplied) > self::TOLERANCE) {
                throw new LoanPaymentPostingException('Principal + interest does not equal the amount applied.');
            }
            if (abs($principalPortion - $computedPrincipalPortion) > self::TOLERANCE
                || abs($interestPortion - $computedInterestPortion) > self::TOLERANCE) {
                $warnings[] = 'Principal/interest split does not match interest-first amortization.';
            }
        } else {
            $principalPortion = $computedPrincipalPortion;
            $interestPortion = $computedInterestPortion;
        }

        $statusBefore = $loan->status;

        $newPrincipalBalance = max(0, round($principalBalanceBefore - $principalPortion, 2));
        $newInterestBalance = max(0, round($interestBalanceBefore - $interestPortion, 2));
        $newOutstandingBalance = round($newPrincipalBalance + $newInterestBalance, 2);
        $newStatus = $newOutstandingBalance <= 0 ? 'Fully Paid' : ($loan->status === 'Released' ? 'Active' : $loan->status);

        $payment = LoanPayment::create([
            'member_id' => $loan->member_id,
            'loan_application_id' => $loan->id,
            'payment_date' => $meta['paymentDate'],
            'amount_paid' => $amountPaid,
            'principal_portion' => $principalPortion,
            'interest_portion' => $interestPortion,
            'penalty' => $penalty,
            'payment_method' => $meta['paymentMethod'],
            'payroll_reference' => $meta['payrollReference'] ?? null,
            'official_receipt_number' => $meta['officialReceiptNumber'] ?? null,
            'received_by' => $meta['receivedBy'] ?? null,
            'remarks' => $meta['remarks'] ?? null,
            'status' => 'Posted',
        ]);
        $payment->update([
            'payment_reference_number' => app(DocumentNumberService::class)->generate('loanPayment', $payment->id, $payment->payment_date),
            // No physical receipt on hand (e.g. payroll-deducted payments) — auto-assign an
            // internal OR number so every payment still has one, instead of leaving it blank.
            'official_receipt_number' => $payment->official_receipt_number
                ?: app(DocumentNumberService::class)->generate('officialReceipt', $payment->id, $payment->payment_date),
        ]);

        $loan->update([
            'outstanding_balance' => $newOutstandingBalance,
            'principal_balance' => $newPrincipalBalance,
            'interest_balance' => $newInterestBalance,
            'status' => $newStatus,
        ]);

        $this->applyPaymentToSchedule($loan, $amountApplied);

        return new LoanPaymentPostResult(
            payment: $payment,
            principalBalanceBefore: $principalBalanceBefore,
            interestBalanceBefore: $interestBalanceBefore,
            statusBefore: $statusBefore,
            principalBalanceAfter: $newPrincipalBalance,
            interestBalanceAfter: $newInterestBalance,
            statusAfter: $newStatus,
            warnings: $warnings,
        );
    }

    /**
     * Marks amortization schedule rows as Paid/Partially Paid as the payment is applied,
     * oldest installment first — the schedule (Loan Details' Amortization Schedule tab)
     * otherwise never reflects that a payment was posted. Public so the one-time backfill
     * command can replay pre-existing payments that predate this method.
     */
    public function applyPaymentToSchedule(Loan $loan, float $amountApplied): void
    {
        $remaining = $amountApplied;

        $rows = $loan->schedule()
            ->whereNotIn('status', ['Paid', 'Waived'])
            ->orderBy('installment_number')
            ->get();

        foreach ($rows as $row) {
            if ($remaining <= self::TOLERANCE) {
                break;
            }

            $due = round((float) $row->amount_due, 2);
            $alreadyPaid = round((float) $row->amount_paid, 2);
            $owedOnRow = max(0, round($due - $alreadyPaid, 2));

            if ($owedOnRow <= self::TOLERANCE) {
                continue;
            }

            $applyToRow = min($remaining, $owedOnRow);
            $newAmountPaid = round($alreadyPaid + $applyToRow, 2);

            $row->update([
                'amount_paid' => $newAmountPaid,
                'status' => $newAmountPaid + self::TOLERANCE >= $due ? 'Paid' : 'Partially Paid',
            ]);

            $remaining = round($remaining - $applyToRow, 2);
        }
    }

    private function automaticPenalty(Loan $loan, string $paymentDate, LoanSetting $policy): float
    {
        if ((float) $policy->default_penalty_rate <= 0) {
            return 0.0;
        }

        $cutoff = Carbon::parse($paymentDate)
            ->subDays((int) $policy->grace_period_days)
            ->toDateString();
        $overdueAmount = (float) $loan->schedule()
            ->whereDate('due_date', '<', $cutoff)
            ->whereIn('status', ['Upcoming', 'Due', 'Overdue', 'Partially Paid'])
            ->sum('amount_due');

        return round($overdueAmount * (float) $policy->default_penalty_rate / 100, 2);
    }
}
