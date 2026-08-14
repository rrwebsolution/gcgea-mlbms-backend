<?php

namespace App\Services;

use App\Models\BenefitApplication;
use App\Models\Contribution;
use App\Models\Loan;
use App\Models\LoanPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class FinancialReportService
{
    public function generate(array $filters): array
    {
        [$start, $end] = $this->dates($filters);

        $contributions = Contribution::query()
            ->where('status', 'Posted')
            ->whereBetween('payment_date', [$start, $end]);
        $this->office($contributions, $filters['officeId'] ?? null, 'member');

        $loanPayments = LoanPayment::query()
            ->where('status', 'Posted')
            ->whereBetween('payment_date', [$start, $end]);
        $this->office($loanPayments, $filters['officeId'] ?? null, 'member');

        $benefits = BenefitApplication::query()
            ->whereIn('status', ['Released', 'Completed'])
            ->whereBetween('release_date', [$start, $end]);
        $this->office($benefits, $filters['officeId'] ?? null, 'member');

        $loans = Loan::query()
            ->whereNotNull('release_date')
            ->whereDate('release_date', '<=', $end)
            ->whereNotIn('status', ['Draft', 'Submitted', 'Rejected', 'Cancelled']);
        $this->office($loans, $filters['officeId'] ?? null, 'member');

        $paymentsThroughEnd = LoanPayment::query()
            ->where('status', 'Posted')
            ->whereDate('payment_date', '<=', $end)
            ->selectRaw('loan_application_id, COALESCE(SUM(principal_portion), 0) principal_paid, COALESCE(SUM(interest_portion), 0) interest_paid')
            ->groupBy('loan_application_id');

        $outstanding = $loans->leftJoinSub($paymentsThroughEnd, 'report_payments', function ($join) {
            $join->on('loans.id', '=', 'report_payments.loan_application_id');
        })->selectRaw(
            'COALESCE(SUM(CASE WHEN loans.principal - COALESCE(report_payments.principal_paid, 0) > 0 THEN loans.principal - COALESCE(report_payments.principal_paid, 0) ELSE 0 END), 0) principal_balance, '.
            'COALESCE(SUM(CASE WHEN loans.total_interest - COALESCE(report_payments.interest_paid, 0) > 0 THEN loans.total_interest - COALESCE(report_payments.interest_paid, 0) ELSE 0 END), 0) interest_balance'
        )->first();

        $summary = [
            'monthlyDuesCollected' => $this->money((clone $contributions)->where('contribution_type', 'Monthly Dues')->sum('amount')),
            'cashPabaonCollected' => $this->money((clone $contributions)->where('contribution_type', 'Cash Pabaon')->sum('amount')),
            'loanPrincipalCollected' => $this->money((clone $loanPayments)->sum('principal_portion')),
            'loanInterestCollected' => $this->money((clone $loanPayments)->sum('interest_portion')),
            'benefitsReleased' => $this->money((clone $benefits)->sum('actual_released_amount')),
            'outstandingLoanBalance' => $this->money((float) $outstanding->principal_balance + (float) $outstanding->interest_balance),
        ];

        return [
            'fiscalYear' => (int) $filters['fiscalYear'],
            'periodStart' => $start->toDateString(),
            'periodEnd' => $end->toDateString(),
            'reportingPeriodLabel' => $this->periodLabel($filters['reportingPeriod'], $start, $end),
            'status' => 'UNAUDITED',
            'generatedAt' => now()->toIso8601String(),
            'summary' => $summary,
        ];
    }

    private function dates(array $filters): array
    {
        if ($filters['reportingPeriod'] === 'custom') {
            return [CarbonImmutable::parse($filters['startDate'])->startOfDay(), CarbonImmutable::parse($filters['endDate'])->endOfDay()];
        }

        $year = (int) $filters['fiscalYear'];
        $anchor = now()->year === $year
            ? CarbonImmutable::now()
            : CarbonImmutable::create($year, 12, 31);

        return match ($filters['reportingPeriod']) {
            'monthly' => [$anchor->startOfMonth(), $anchor->endOfMonth()],
            'quarterly' => [$anchor->startOfQuarter(), $anchor->endOfQuarter()],
            'semi_annual' => $anchor->month <= 6
                ? [CarbonImmutable::create($year, 1, 1)->startOfDay(), CarbonImmutable::create($year, 6, 30)->endOfDay()]
                : [CarbonImmutable::create($year, 7, 1)->startOfDay(), CarbonImmutable::create($year, 12, 31)->endOfDay()],
            default => [CarbonImmutable::create($year, 1, 1)->startOfDay(), CarbonImmutable::create($year, 12, 31)->endOfDay()],
        };
    }

    private function periodLabel(string $period, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $period === 'annual'
            ? 'For the Year Ended December 31, '.$end->year
            : 'For the Period '.$start->format('F j, Y').' to '.$end->format('F j, Y');
    }

    private function office(Builder $query, mixed $officeId, string $relation): void
    {
        if ($officeId) {
            $query->whereHas($relation, fn (Builder $member) => $member->where('office_id', $officeId));
        }
    }

    private function money(mixed $amount): float
    {
        return round((float) $amount, 2);
    }
}
