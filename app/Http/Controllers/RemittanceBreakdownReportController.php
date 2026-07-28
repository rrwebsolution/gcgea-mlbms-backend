<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\Loan;
use App\Models\LoanPayment;
use Illuminate\Http\Request;

class RemittanceBreakdownReportController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.view'), 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);
        $year = (int) $data['year'];
        $rows = [];

        foreach (range(1, 12) as $month) {
            $rows[sprintf('%04d-%02d', $year, $month)] = $this->emptyRow(
                sprintf('%04d-%02d', $year, $month),
                'month'
            );
        }
        $rows['OTC'] = $this->emptyRow('OTC', 'otc');

        LoanPayment::query()
            ->where('status', 'Posted')
            ->whereYear('payment_date', $year)
            ->get(['payment_date', 'payment_method', 'principal_portion', 'interest_portion', 'penalty'])
            ->each(function (LoanPayment $payment) use (&$rows): void {
                $key = $payment->payment_method === 'Payroll Deduction'
                    ? $payment->payment_date->format('Y-m')
                    : 'OTC';
                $rows[$key]['principal'] += (float) $payment->principal_portion;
                $rows[$key]['interest'] += (float) $payment->interest_portion;
                $rows[$key]['serviceIncome'] += (float) $payment->penalty;
            });

        Contribution::query()
            ->where('status', 'Posted')
            ->where('contribution_type', 'Monthly Dues')
            ->whereYear('payment_date', $year)
            ->get(['payment_date', 'payment_method', 'amount'])
            ->each(function (Contribution $contribution) use (&$rows): void {
                $key = $contribution->payment_method === 'Payroll Deduction'
                    ? $contribution->payment_date->format('Y-m')
                    : 'OTC';
                $rows[$key]['monthlyDues'] += (float) $contribution->amount;
            });

        Deduction::query()
            ->where('status', 'Posted')
            ->whereYear('payment_date', $year)
            ->whereHas('deductionType', fn ($query) => $query->where('code', 'pabaon'))
            ->get(['payment_date', 'payroll_reference', 'amount'])
            ->each(function (Deduction $deduction) use (&$rows): void {
                $key = filled($deduction->payroll_reference)
                    ? $deduction->payment_date->format('Y-m')
                    : 'OTC';
                $rows[$key]['cashPabaon'] += (float) $deduction->amount;
            });

        $normalizedRows = collect($rows)
            ->map(function (array $row): array {
                foreach (['principal', 'interest', 'serviceIncome', 'monthlyDues', 'cashPabaon'] as $field) {
                    $row[$field] = round($row[$field], 2);
                }
                $row['total'] = round(
                    $row['principal']
                    + $row['interest']
                    + $row['serviceIncome']
                    + $row['monthlyDues']
                    + $row['cashPabaon'],
                    2
                );

                return $row;
            })
            ->values();

        $totals = [
            'principal' => round($normalizedRows->sum('principal'), 2),
            'interest' => round($normalizedRows->sum('interest'), 2),
            'serviceIncome' => round($normalizedRows->sum('serviceIncome'), 2),
            'monthlyDues' => round($normalizedRows->sum('monthlyDues'), 2),
            'cashPabaon' => round($normalizedRows->sum('cashPabaon'), 2),
            'total' => round($normalizedRows->sum('total'), 2),
        ];

        return response()->json([
            'year' => $year,
            'rows' => $normalizedRows,
            'totals' => $totals,
            'loanReceivables' => round((float) Loan::query()
                ->whereIn('status', ['Released', 'Active', 'Overdue'])
                ->sum('outstanding_balance'), 2),
            'asOfDate' => now()->toDateString(),
        ]);
    }

    private function emptyRow(string $period, string $kind): array
    {
        return [
            'period' => $period,
            'kind' => $kind,
            'principal' => 0.0,
            'interest' => 0.0,
            'serviceIncome' => 0.0,
            'monthlyDues' => 0.0,
            'cashPabaon' => 0.0,
            'total' => 0.0,
        ];
    }
}
