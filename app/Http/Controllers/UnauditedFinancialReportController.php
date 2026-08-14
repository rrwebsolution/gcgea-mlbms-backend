<?php

namespace App\Http\Controllers;

use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnauditedFinancialReportController extends Controller
{
    public function generate(Request $request, FinancialReportService $reports)
    {
        abort_unless($request->user()->hasPermission('reports.financial'), 403);

        $filters = $request->validate([
            'fiscalYear' => ['required', 'integer', 'min:1997', 'max:9999'],
            'reportingPeriod' => ['required', Rule::in(['monthly', 'quarterly', 'semi_annual', 'annual', 'custom'])],
            'startDate' => ['required_if:reportingPeriod,custom', 'nullable', 'date'],
            'endDate' => ['required_if:reportingPeriod,custom', 'nullable', 'date', 'after_or_equal:startDate'],
            'officeId' => ['nullable', 'integer', 'exists:offices,id'],
            'transactionStatus' => ['required', Rule::in(['posted', 'verified'])],
        ]);

        return response()->json($reports->generate($filters));
    }
}
