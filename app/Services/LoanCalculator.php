<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Ports src/utils/loan-math.ts computeLoan() 1:1, quirks included:
 *  - "annualRatePercent" is actually used directly as a MONTHLY rate (no /12).
 *  - "Custom" interest method has no distinct formula — it falls into the same
 *    diminishing-balance branch as "Diminishing Balance". Not a bug to fix here.
 *  - The last installment always absorbs whatever balance/rounding remains.
 */
class LoanCalculator
{
    /**
     * @return array{principal: float, totalInterest: float, processingFee: float, netProceeds: float, totalAmountPayable: float, monthlyAmortization: float, maturityDate: string, schedule: array<int, array<string, mixed>>}
     */
    public function compute(
        float $principal,
        float $annualRatePercent,
        int $termMonths,
        float $processingFee,
        string $interestMethod,
        Carbon $firstDueDate,
    ): array {
        $monthlyRate = $annualRatePercent / 100;
        $totalInterest = 0.0;
        $schedule = [];

        if ($interestMethod === 'Zero Interest') {
            $flatMonthly = round($principal / $termMonths, 2);
            $balance = $principal;
            for ($i = 1; $i <= $termMonths; $i++) {
                $principalPortion = $i === $termMonths ? $balance : $flatMonthly;
                $beginningBalance = $balance;
                $balance = max(0, $balance - $principalPortion);
                $schedule[] = [
                    'installment_number' => $i,
                    'due_date' => $firstDueDate->copy()->addMonths($i - 1)->toDateString(),
                    'beginning_balance' => $beginningBalance,
                    'principal' => $principalPortion,
                    'interest' => 0,
                    'penalty' => 0,
                    'amount_due' => $principalPortion,
                    'amount_paid' => 0,
                    'remaining_balance' => $balance,
                    'status' => 'Upcoming',
                ];
            }
        } elseif ($interestMethod === 'Flat Interest') {
            $totalInterest = round($principal * $monthlyRate * $termMonths, 2);
            $monthlyPrincipal = round($principal / $termMonths, 2);
            $monthlyInterest = round($totalInterest / $termMonths, 2);
            $balance = $principal;
            for ($i = 1; $i <= $termMonths; $i++) {
                $principalPortion = $i === $termMonths ? $balance : $monthlyPrincipal;
                $beginningBalance = $balance;
                $balance = max(0, $balance - $principalPortion);
                $schedule[] = [
                    'installment_number' => $i,
                    'due_date' => $firstDueDate->copy()->addMonths($i - 1)->toDateString(),
                    'beginning_balance' => $beginningBalance,
                    'principal' => $principalPortion,
                    'interest' => $monthlyInterest,
                    'penalty' => 0,
                    'amount_due' => $principalPortion + $monthlyInterest,
                    'amount_paid' => 0,
                    'remaining_balance' => $balance,
                    'status' => 'Upcoming',
                ];
            }
        } else {
            // Diminishing Balance / Custom — standard amortizing (annuity) formula.
            $r = $monthlyRate;
            $payment = $r === 0.0
                ? $principal / $termMonths
                : ($principal * $r * (1 + $r) ** $termMonths) / ((1 + $r) ** $termMonths - 1);
            $balance = $principal;
            for ($i = 1; $i <= $termMonths; $i++) {
                $interestPortion = round($balance * $r, 2);
                $principalPortion = round($payment - $interestPortion, 2);
                if ($i === $termMonths) {
                    $principalPortion = $balance;
                }
                $beginningBalance = $balance;
                $balance = max(0, round($balance - $principalPortion, 2));
                $totalInterest += $interestPortion;
                $schedule[] = [
                    'installment_number' => $i,
                    'due_date' => $firstDueDate->copy()->addMonths($i - 1)->toDateString(),
                    'beginning_balance' => $beginningBalance,
                    'principal' => $principalPortion,
                    'interest' => $interestPortion,
                    'penalty' => 0,
                    'amount_due' => $principalPortion + $interestPortion,
                    'amount_paid' => 0,
                    'remaining_balance' => $balance,
                    'status' => 'Upcoming',
                ];
            }
            $totalInterest = round($totalInterest, 2);
        }

        $netProceeds = round($principal - $processingFee, 2);
        $totalAmountPayable = round($principal + $totalInterest, 2);
        $monthlyAmortization = $schedule[0]['amount_due'] ?? 0;
        $maturityDate = $schedule !== [] ? $schedule[count($schedule) - 1]['due_date'] : $firstDueDate->toDateString();

        return [
            'principal' => $principal,
            'totalInterest' => $totalInterest,
            'processingFee' => $processingFee,
            'netProceeds' => $netProceeds,
            'totalAmountPayable' => $totalAmountPayable,
            'monthlyAmortization' => $monthlyAmortization,
            'maturityDate' => $maturityDate,
            'schedule' => $schedule,
        ];
    }
}
