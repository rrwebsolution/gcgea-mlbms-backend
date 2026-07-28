<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkPayrollDeductionRequest;
use App\Models\Loan;
use App\Models\PayrollDeductionHeader;
use App\Repositories\PayrollDeductionRepository;
use App\Services\AuditLogService;
use App\Services\PayrollDeductionPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bulk Entry encodes payroll deductions for many members at once. Rather than
 * a separate "batch" table, a batch is simply every payroll_deduction_headers
 * row that shares one payroll_reference — reusing the exact same
 * header/detail schema and posting logic Manual Entry already relies on
 * (see ManualPayrollDeductionController), just looped per member.
 */
class BulkPayrollDeductionController extends Controller
{
    public function __construct(
        private readonly PayrollDeductionRepository $repository,
        private readonly PayrollDeductionPostingService $postingService,
        private readonly AuditLogService $auditLog,
    ) {}

    public function reference(): JsonResponse
    {
        $year = now()->year;
        $next = ((int) PayrollDeductionHeader::query()->whereYear('created_at', $year)->max('id')) + 1;

        return response()->json(['payrollReference' => sprintf('PD-%d-%06d', $year, $next)]);
    }

    public function membersContext(Request $request): JsonResponse
    {
        // `present` (not `required`) — an office with zero active members
        // legitimately sends an empty array, and Laravel's `required` rule
        // treats an empty array as "missing" and rejects it.
        $data = $request->validate([
            'memberIds' => ['present', 'array'],
            'memberIds.*' => ['integer', 'exists:members,id'],
        ]);

        $loans = Loan::query()
            ->whereIn('member_id', $data['memberIds'])
            ->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])
            ->get(['id', 'member_id', 'application_number', 'principal', 'principal_balance', 'interest_balance', 'monthly_amortization', 'outstanding_balance'])
            ->groupBy('member_id')
            ->map(fn ($group) => $group->sortByDesc('id')->first());

        $context = [];
        foreach ($data['memberIds'] as $memberId) {
            $loan = $loans->get($memberId);
            $context[(string) $memberId] = [
                'hasActiveLoan' => (bool) $loan,
                'loanNumber' => $loan?->application_number,
                'loanableAmount' => $loan ? (float) $loan->principal : null,
                'outstandingPrincipal' => $loan ? (float) $loan->principal_balance : null,
                'outstandingInterest' => $loan ? (float) $loan->interest_balance : null,
                'outstandingBalance' => $loan ? (float) $loan->outstanding_balance : null,
                'loanDeduction' => $loan ? min((float) $loan->monthly_amortization, (float) $loan->outstanding_balance) : 0,
            ];
        }

        return response()->json($context);
    }

    public function store(BulkPayrollDeductionRequest $request): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request): void {
            foreach ($data['rows'] as $row) {
                $this->repository->createDraft([
                    'payroll_reference' => $data['payrollReference'],
                    'payroll_period' => $data['payrollPeriod'],
                    'payroll_date' => $data['payrollDate'],
                    'office_id' => $data['officeId'],
                    'member_id' => $row['memberId'],
                    'remarks' => $data['remarks'] ?? null,
                    'loanable_amount' => $row['loanableAmount'] ?? null,
                    'outstanding_interest' => $row['outstandingInterest'] ?? null,
                    'outstanding_balance' => $row['outstandingBalance'] ?? null,
                    'total_deduction' => $row['monthlyDues'] + $row['cashPabaon'] + $row['loanDeduction'],
                    'status' => 'Draft',
                    'created_by_user_id' => $request->user()->id,
                ], ['monthly_dues' => $row['monthlyDues'], 'cash_pabaon' => $row['cashPabaon'], 'loan' => $row['loanDeduction']]);
            }
        });

        return response()->json($this->summarize($data['payrollReference']));
    }

    public function post(Request $request, string $reference): JsonResponse
    {
        abort_unless($request->user()->hasPermission('payroll.bulk.post'), 403);

        $headers = PayrollDeductionHeader::query()->where('payroll_reference', $reference)->where('status', 'Draft')->get();
        abort_if($headers->isEmpty(), 404, 'No draft payroll deductions found for this reference.');

        DB::transaction(function () use ($headers, $request): void {
            foreach ($headers as $header) {
                $this->postingService->post($header, $request->user());
            }
            $this->auditLog->record($request->user(), $headers->first(), 'bulk_payroll_posted');
        });

        return response()->json($this->summarize($reference));
    }

    private function summarize(string $reference): array
    {
        $headers = PayrollDeductionHeader::query()->where('payroll_reference', $reference)->with('postedBy')->get();
        $first = $headers->first();
        $lastPosted = $headers->whereNotNull('posted_at')->sortByDesc('posted_at')->first();

        return [
            'id' => $reference,
            'payrollReference' => $reference,
            'payrollPeriod' => $first?->payroll_period,
            'officeId' => (string) $first?->office_id,
            'status' => $headers->every(fn ($h) => $h->status === 'Posted') ? 'Posted' : 'Draft',
            'memberCount' => $headers->count(),
            'totalDeduction' => (float) $headers->sum('total_deduction'),
            'postedBy' => $lastPosted?->postedBy?->full_name,
            'postedAt' => $lastPosted?->posted_at?->toIso8601String(),
        ];
    }
}
