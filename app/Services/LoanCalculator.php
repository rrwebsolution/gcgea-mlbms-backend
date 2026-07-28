<?php

namespace App\Services;

use App\Models\LoanSetting;
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
     * @return array{principal: float, totalInterest: float, processingFee: float, serviceCharge: float, netProceeds: float, totalAmountPayable: float, monthlyAmortization: float, maturityDate: string, schedule: array<int, array<string, mixed>>}
     */
    public function compute(
        float $principal,
        float $annualRatePercent,
        int $termMonths,
        float $processingFee,
        string $interestMethod,
        Carbon $firstDueDate,
        // 1%-of-gross service charge (Resolution No. 24-2026, Table 3) — additive
        // to the existing flat processingFee, not a replacement for it. Null for
        // every loan type except Solidarity Cash Assistance today.
        ?float $serviceChargePercent = null,
    ): array {
        $serviceCharge = $serviceChargePercent ? $this->money($principal * $serviceChargePercent / 100) : 0.0;
        $monthlyRate = $annualRatePercent / 100;
        $totalInterest = 0.0;
        $schedule = [];

        if ($interestMethod === 'Zero Interest') {
            $flatMonthly = $this->money($principal / $termMonths);
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
            $totalInterest = $this->money($principal * $monthlyRate * $termMonths);
            $monthlyPrincipal = $this->money($principal / $termMonths);
            $monthlyInterest = $this->money($totalInterest / $termMonths);
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
                $interestPortion = $this->money($balance * $r);
                $principalPortion = $this->money($payment - $interestPortion);
                if ($i === $termMonths) {
                    $principalPortion = $balance;
                }
                $beginningBalance = $balance;
                $balance = max(0, $this->money($balance - $principalPortion));
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
            $totalInterest = $this->money($totalInterest);
        }

        $netProceeds = $this->money($principal - $processingFee - $serviceCharge);
        $totalAmountPayable = $this->money($principal + $totalInterest);
        $monthlyAmortization = $schedule[0]['amount_due'] ?? 0;
        $maturityDate = $schedule !== [] ? $schedule[count($schedule) - 1]['due_date'] : $firstDueDate->toDateString();

        return [
            'principal' => $principal,
            'totalInterest' => $totalInterest,
            'processingFee' => $processingFee,
            'serviceCharge' => $serviceCharge,
            'netProceeds' => $netProceeds,
            'totalAmountPayable' => $totalAmountPayable,
            'monthlyAmortization' => $monthlyAmortization,
            'maturityDate' => $maturityDate,
            'schedule' => $schedule,
        ];
    }

    private function money(float $amount): float
    {
        return match (LoanSetting::current()->rounding_rule) {
            'Nearest Peso' => round($amount, 0),
            'Round Up' => ceil($amount * 100) / 100,
            'Round Down' => floor($amount * 100) / 100,
            default => round($amount, 2),
        };
    }
}
