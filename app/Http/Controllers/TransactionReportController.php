<?php

namespace App\Http\Controllers;

use App\Models\BenefitApplication;
use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\Disbursement;
use App\Models\LoanPayment;
use App\Models\MembershipFeePayment;
use Illuminate\Http\Request;

class TransactionReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('reports.financial'), 403);
        $filters = $request->validate(['startDate' => ['nullable', 'date'], 'endDate' => ['nullable', 'date', 'after_or_equal:startDate'], 'type' => ['nullable', 'string']]);
        $start = $filters['startDate'] ?? now()->startOfYear()->toDateString();
        $end = $filters['endDate'] ?? now()->toDateString();
        $transactions = collect();

        Contribution::with('member')->where('status', 'Posted')->whereBetween('payment_date', [$start, $end])->get()->each(function ($item) use ($transactions) {
            $transactions->push($this->row('Contribution Payment', 'Inflow', $item->payment_date, $item->reference_number, $item->member?->full_name, $item->amount, $item->payment_method, $item->status, $item->contribution_type));
        });
        LoanPayment::with('member')->where('status', 'Posted')->whereBetween('payment_date', [$start, $end])->get()->each(function ($item) use ($transactions) {
            $transactions->push($this->row('Loan Payment', 'Inflow', $item->payment_date, $item->payment_reference_number, $item->member?->full_name, $item->amount_paid, $item->payment_method, $item->status, 'Principal, interest and penalties received'));
        });
        MembershipFeePayment::with('member')->where('status', 'Posted')->whereBetween('payment_date', [$start, $end])->get()->each(function ($item) use ($transactions) {
            $transactions->push($this->row('Membership Payment', 'Inflow', $item->payment_date, $item->reference_number, $item->member?->full_name, $item->amount, $item->payment_method, $item->status, 'Membership registration fee'));
        });
        Deduction::with(['member', 'deductionType'])->where('status', 'Posted')->whereBetween('payment_date', [$start, $end])->get()->each(function ($item) use ($transactions) {
            $transactions->push($this->row('Other Payment', 'Inflow', $item->payment_date, $item->reference_number, $item->member?->full_name, $item->amount, 'Payroll Deduction', $item->status, $item->deductionType?->name ?? 'Deduction'));
        });
        BenefitApplication::with(['member', 'benefitType'])->whereIn('status', ['Released', 'Completed'])->whereBetween('release_date', [$start, $end])->get()->each(function ($item) use ($transactions) {
            $transactions->push($this->row('Benefit Payment', 'Outflow', $item->release_date, $item->release_reference_number, $item->member?->full_name, $item->actual_released_amount, 'Benefit Release', $item->status, $item->benefitType?->name));
        });
        Disbursement::where('status', 'Paid')->whereBetween('disbursement_date', [$start, $end])->get()->each(function ($item) use ($transactions) {
            $transactions->push($this->row('Other Payment', 'Outflow', $item->disbursement_date, $item->reference_number, $item->payee, $item->amount, $item->payment_method, $item->status, $item->remarks ?: 'Disbursement'));
        });

        if (! empty($filters['type']) && $filters['type'] !== 'All') {
            $transactions = $transactions->where('type', $filters['type']);
        }
        $transactions = $transactions->sortByDesc(fn ($row) => $row['date'].'-'.$row['reference'])->values();
        $total = $transactions->count();
        $summary = ['inflow' => round((float) $transactions->where('direction', 'Inflow')->sum('amount'), 2), 'outflow' => round((float) $transactions->where('direction', 'Outflow')->sum('amount'), 2), 'count' => $total];

        return response()->json([
            'periodStart' => $start, 'periodEnd' => $end, 'transactions' => $transactions,
            'summary' => $summary,
        ]);
    }

    private function row(string $type, string $direction, mixed $date, ?string $reference, ?string $party, mixed $amount, ?string $method, string $status, ?string $details): array
    {
        return ['id' => $type.'-'.$reference, 'date' => $date?->toDateString() ?? (string) $date, 'reference' => $reference ?: '—', 'type' => $type, 'direction' => $direction, 'party' => $party ?: '—', 'details' => $details ?: '—', 'amount' => round((float) $amount, 2), 'method' => $method ?: '—', 'status' => $status];
    }
}
