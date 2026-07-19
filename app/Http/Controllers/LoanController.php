<?php

namespace App\Http\Controllers;

use App\Http\Requests\Loan\LoanRequest;
use App\Http\Resources\AmortizationEntryResource;
use App\Http\Resources\ApprovalHistoryEntryResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\LoanType;
use App\Models\Member;
use App\Services\LoanCalculator;
use App\Services\LoanEligibilityService;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $query = Loan::with(['member.office', 'loanType']);
        $this->applyFilters($query, $request);
        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, LoanResource::class));
    }

    public function all(Request $request)
    {
        $query = Loan::with(['member.office', 'loanType']);
        $this->applyFilters($query, $request);

        return LoanResource::collection($query->orderBy('application_date', 'desc')->get());
    }

    public function show(Loan $loan)
    {
        return new LoanResource($loan->load(['member.office', 'loanType']));
    }

    public function schedule(Loan $loan)
    {
        return AmortizationEntryResource::collection($loan->schedule);
    }

    public function approvalHistory(Loan $loan)
    {
        return ApprovalHistoryEntryResource::collection($loan->approvalHistory);
    }

    public function store(LoanRequest $request, LoanCalculator $calculator, LoanEligibilityService $eligibilityService)
    {
        if (! $request->user()->hasPermission('loans.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.create')) {
            abort(403, "You don't have permission to save drafts.");
        }

        $member = Member::findOrFail($request->input('memberId'));
        $computed = $this->computeLoanFields($request, $calculator, $eligibilityService, $member);

        $loan = DB::transaction(function () use ($request, $member, $asDraft, $computed) {
            $loan = Loan::create([
                'application_date' => $computed['applicationDate'],
                'member_id' => $member->id,
                ...$computed['columns'],
                'status' => $asDraft ? 'Draft' : 'Submitted',
                'draft_current_step' => $request->integer('draftCurrentStep', 1),
                'assigned_officer' => $request->user()->full_name,
                'requirements' => $request->input('requirements', []),
                'created_by' => $request->user()->full_name,
            ]);
            $loan->update(['application_number' => 'GCGEA-LN-'.$computed['applicationDate']->year.'-'.str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($computed['schedule'] as $entry) {
                $loan->schedule()->create($entry);
            }

            $loan->approvalHistory()->create([
                'action' => $asDraft ? 'Draft Created' : 'Submitted',
                'performed_by' => $request->user()->full_name,
                'performed_at' => now(),
            ]);

            return $loan;
        });

        return new LoanResource($loan->load(['member.office', 'loanType']));
    }

    /**
     * Edits a draft loan in place — never available once it has moved past
     * Draft status (the approval workflow, not this endpoint, owns it then).
     */
    public function update(LoanRequest $request, LoanCalculator $calculator, LoanEligibilityService $eligibilityService, Loan $loan)
    {
        if (! $request->user()->hasPermission('loans.update')) {
            abort(403, "You don't have permission to perform this action.");
        }
        if ($loan->status !== 'Draft') {
            abort(403, 'Only draft loan applications can be edited directly.');
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.update_own')) {
            abort(403, "You don't have permission to save drafts.");
        }

        $member = Member::findOrFail($request->input('memberId'));
        $computed = $this->computeLoanFields($request, $calculator, $eligibilityService, $member);

        DB::transaction(function () use ($request, $member, $asDraft, $computed, $loan) {
            $loan->update([
                'member_id' => $member->id,
                ...$computed['columns'],
                'status' => $asDraft ? 'Draft' : 'Submitted',
                'draft_current_step' => $request->integer('draftCurrentStep', $loan->draft_current_step ?? 1),
                'requirements' => $request->input('requirements', []),
            ]);

            $loan->schedule()->delete();
            foreach ($computed['schedule'] as $entry) {
                $loan->schedule()->create($entry);
            }

            $loan->approvalHistory()->create([
                'action' => $asDraft ? 'Draft Updated' : 'Submitted',
                'performed_by' => $request->user()->full_name,
                'performed_at' => now(),
            ]);
        });

        return new LoanResource($loan->load(['member.office', 'loanType']));
    }

    /**
     * Shared compute step for store()/update() — returns null-safe column
     * values plus a schedule array (empty when the loan isn't fully
     * specified yet, which is expected for an early-stage draft).
     *
     * @return array{applicationDate: \Illuminate\Support\Carbon, columns: array<string, mixed>, schedule: array<int, array<string, mixed>>}
     */
    private function computeLoanFields(LoanRequest $request, LoanCalculator $calculator, LoanEligibilityService $eligibilityService, Member $member): array
    {
        $applicationDate = now();
        $firstDueDate = $applicationDate->copy()->addMonth();

        $loanTypeId = $request->input('loanTypeId');
        $loanType = $loanTypeId ? LoanType::find($loanTypeId) : null;
        $requestedAmount = $request->filled('requestedAmount') ? (float) $request->input('requestedAmount') : null;
        $termMonths = $request->filled('termMonths') ? (int) $request->input('termMonths') : null;

        $computation = null;
        $eligibility = [];
        if ($loanType && $requestedAmount && $termMonths) {
            $computation = $calculator->compute(
                $requestedAmount,
                (float) $loanType->default_interest_rate,
                $termMonths,
                (float) $loanType->processing_fee,
                $loanType->interest_method,
                $firstDueDate,
            );
            $eligibility = $eligibilityService->evaluate($member, $loanType, $requestedAmount, $termMonths);
        }

        return [
            'applicationDate' => $applicationDate,
            'columns' => [
                'loan_type_id' => $loanType?->id,
                'requested_amount' => $requestedAmount,
                'term_months' => $termMonths,
                'interest_rate' => $loanType?->default_interest_rate,
                'processing_fee' => $loanType?->processing_fee,
                'purpose' => $request->input('purpose'),
                'payment_method' => $request->input('paymentMethod'),
                'first_due_date' => $computation ? $firstDueDate : null,
                'maturity_date' => $computation['maturityDate'] ?? null,
                'principal' => $computation['principal'] ?? null,
                'total_interest' => $computation['totalInterest'] ?? null,
                'net_proceeds' => $computation['netProceeds'] ?? null,
                'total_amount_payable' => $computation['totalAmountPayable'] ?? null,
                'monthly_amortization' => $computation['monthlyAmortization'] ?? null,
                'outstanding_balance' => 0,
                'eligibility' => $eligibility,
            ],
            'schedule' => $computation['schedule'] ?? [],
        ];
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('application_number', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($mq) => $mq->where('surname', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%")
                        ->orWhere('member_number', 'ilike', "%{$search}%"));
            });
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($loanTypeId = $request->string('loanTypeId')->toString()) {
            $query->where('loan_type_id', $loanTypeId);
        }
        if ($office = $request->string('office')->toString()) {
            $query->whereHas('member.office', fn ($q) => $q->where('name', $office));
        }
        if ($request->boolean('overdueOnly')) {
            $query->where('status', 'Overdue');
        }
    }
}
