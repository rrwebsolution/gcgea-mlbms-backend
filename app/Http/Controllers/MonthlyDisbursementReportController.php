<?php

namespace App\Http\Controllers;

use App\Models\Disbursement;
use App\Models\LoanPayment;
use Illuminate\Http\Request;

class MonthlyDisbursementReportController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->hasPermission('disbursements.view'), 403);
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $year = (int) $data['year'];
        $groups = Disbursement::query()
            ->with('budgetItem')
            ->where('status', 'Paid')
            ->whereYear('disbursement_date', $year)
            ->get()
            ->groupBy(fn ($row) => $row->budgetItem?->account_title ?? 'Uncategorized');

        $rows = $groups->map(function ($records, string $title) {
            $months = array_fill(1, 12, 0.0);
            foreach ($records as $record) $months[$record->disbursement_date->month] += (float) $record->amount;
            return ['particular' => $title, 'months' => array_values($months), 'total' => round(array_sum($months), 2)];
        })->sortBy('particular')->values();

        $interest = array_fill(1, 12, 0.0);
        $service = array_fill(1, 12, 0.0);
        LoanPayment::query()->where('status', 'Posted')->whereYear('payment_date', $year)->get()
            ->each(function ($payment) use (&$interest, &$service) {
                $month = $payment->payment_date->month;
                $interest[$month] += (float) $payment->interest_portion;
                $service[$month] += (float) $payment->penalty;
            });
        $expenses = array_fill(1, 12, 0.0);
        foreach ($rows as $row) foreach ($row['months'] as $index => $amount) $expenses[$index + 1] += $amount;

        return response()->json([
            'year' => $year,
            'rows' => $rows,
            'monthlyTotals' => array_values(array_map(fn ($value) => round($value, 2), $expenses)),
            'grandTotal' => round(array_sum($expenses), 2),
            'incomeSummary' => [
                'interestIncome' => array_values(array_map(fn ($value) => round($value, 2), $interest)),
                'serviceIncome' => array_values(array_map(fn ($value) => round($value, 2), $service)),
                'expenses' => array_values(array_map(fn ($value) => round($value, 2), $expenses)),
            ],
        ]);
    }
}
