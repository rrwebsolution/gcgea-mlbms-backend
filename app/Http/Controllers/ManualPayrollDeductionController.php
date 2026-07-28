<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManualPayrollDeductionRequest;
use App\Http\Resources\ManualPayrollDeductionResource;
use App\Http\Resources\MemberResource;
use App\Models\Loan;
use App\Models\Member;
use App\Models\PayrollDeductionHeader;
use App\Repositories\PayrollDeductionRepository;
use App\Services\AuditLogService;
use App\Services\PayrollDeductionPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManualPayrollDeductionController extends Controller
{
    public function __construct(private readonly PayrollDeductionRepository $repository, private readonly PayrollDeductionPostingService $postingService, private readonly AuditLogService $auditLog) {}

    public function reference(): JsonResponse
    {
        $year = now()->year;
        $next = ((int) PayrollDeductionHeader::query()->whereYear('created_at', $year)->max('id')) + 1;

        return response()->json(['payrollReference' => sprintf('PD-%d-%06d', $year, $next)]);
    }

    public function member(Member $member): JsonResponse
    {
        $member->load(['office', 'beneficiaries', 'documents', 'approvalInstance']);

        // Mirrors LoanEligibilityService::registrationApprovedCheck() — most
        // members here (payroll/manual imports, legacy migrations) were
        // created directly at Active and never routed through the
        // registration workflow, so they have no approval_instances row at
        // all. That's not the same as "not yet approved"; only a row that
        // exists but isn't 'approved' (pending/rejected/returned) fails this.
        $instanceStatus = $member->approvalInstance?->status;
        $registrationApproved = $instanceStatus !== null ? $instanceStatus === 'approved' : $member->membership_status !== 'Pending';

        abort_unless(! $member->is_draft && ! $member->is_archived && $member->membership_status === 'Active' && $registrationApproved, 422, 'Only approved, active members may be selected.');
        $loan = Loan::query()->where('member_id', $member->id)->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured'])->latest()->first();

        return response()->json(['member' => new MemberResource($member), 'activeLoan' => $loan ? [
            'id' => (string) $loan->id, 'loanNumber' => $loan->application_number, 'status' => $loan->status, 'releaseDate' => $loan->release_date?->toDateString(),
            'principalAmount' => (float) $loan->principal, 'outstandingPrincipal' => (float) $loan->principal_balance,
            'outstandingInterest' => (float) $loan->interest_balance, 'remainingBalance' => (float) $loan->outstanding_balance,
            'monthlyAmortization' => min((float) $loan->monthly_amortization, (float) $loan->outstanding_balance),
        ] : null]);
    }

    public function store(ManualPayrollDeductionRequest $request): ManualPayrollDeductionResource
    {
        $data = $request->validated();
        $payroll = DB::transaction(fn () => $this->repository->createDraft([
            'payroll_reference' => $data['payrollReference'], 'payroll_period' => $data['payrollPeriod'], 'payroll_date' => $data['payrollDate'],
            'office_id' => $data['officeId'], 'member_id' => $data['memberId'], 'remarks' => $data['remarks'] ?? null,
            'total_deduction' => $data['monthlyDues'] + $data['cashPabaon'] + $data['loanDeduction'], 'status' => 'Draft', 'created_by_user_id' => $request->user()->id,
        ], ['monthly_dues' => $data['monthlyDues'], 'cash_pabaon' => $data['cashPabaon'], 'loan' => $data['loanDeduction']]));
        $this->auditLog->record($request->user(), $payroll, 'draft_created');

        return new ManualPayrollDeductionResource($payroll);
    }

    public function post(Request $request, PayrollDeductionHeader $payrollDeduction): ManualPayrollDeductionResource
    {
        abort_unless($request->user()->hasPermission('payroll.manual.post'), 403);

        return new ManualPayrollDeductionResource($this->postingService->post($payrollDeduction, $request->user()));
    }
}
