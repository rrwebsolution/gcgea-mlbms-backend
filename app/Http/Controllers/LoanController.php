<?php

namespace App\Http\Controllers;

use App\Http\Requests\Loan\LoanReleaseRequest;
use App\Http\Requests\Loan\LoanRequest;
use App\Http\Resources\AmortizationEntryResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\LoanEligibilityException;
use App\Models\LoanSetting;
use App\Models\LoanType;
use App\Models\Member;
use App\Services\ApprovalWorkflowService;
use App\Services\AuditLogService;
use App\Services\DocumentNumberService;
use App\Services\FundLedgerService;
use App\Services\LoanCalculator;
use App\Services\LoanEligibilityService;
use App\Services\LoanIncomeBracketService;
use App\Services\ReloanEligibilityService;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflow,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request)
    {
        $query = Loan::with(['member.office', 'loanType', 'previousLoan', 'documents']);
        $this->applyFilters($query, $request);
        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, LoanResource::class));
    }

    public function all(Request $request)
    {
        $query = Loan::with(['member.office', 'loanType', 'previousLoan', 'documents']);
        $this->applyFilters($query, $request);

        return LoanResource::collection($query->orderBy('application_date', 'desc')->get());
    }

    public function show(Request $request, Loan $loan)
    {
        $this->guardMemberScope($request, $loan);

        return new LoanResource($loan->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    public function schedule(Request $request, Loan $loan)
    {
        $this->guardMemberScope($request, $loan);

        return AmortizationEntryResource::collection($loan->schedule);
    }

    /**
     * Blocks a member self-service account from reading a loan that isn't
     * their own by ID, even though route-model binding already resolved it.
     */
    private function guardMemberScope(Request $request, Loan $loan): void
    {
        $memberId = $request->user()?->member_id;
        if ($memberId && $loan->member_id !== $memberId) {
            abort(403, "You don't have permission to perform this action.");
        }
    }

    public function review(Request $request, Loan $loan)
    {
        $this->authorize('act', [$loan, 'review']);
        $this->workflow->act($loan, $request->user(), 'review', remarks: $request->input('remarks'));

        return new LoanResource($loan->fresh()->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    public function approve(Request $request, Loan $loan)
    {
        $this->authorize('act', [$loan, 'approve']);
        $this->workflow->act(
            $loan,
            $request->user(),
            'approve',
            ['approved_amount' => $loan->approved_amount ?? $loan->requested_amount],
            $request->input('remarks'),
        );

        return new LoanResource($loan->fresh()->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    public function reject(Request $request, Loan $loan)
    {
        $request->validate(['remarks' => ['required', 'string']]);
        $this->authorize('act', [$loan, 'reject']);
        $this->workflow->act($loan, $request->user(), 'reject', remarks: $request->string('remarks')->toString());

        return new LoanResource($loan->fresh()->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    public function returnForRevision(Request $request, Loan $loan)
    {
        $request->validate(['remarks' => ['required', 'string']]);
        $this->authorize('act', [$loan, 'return']);
        $this->workflow->act($loan, $request->user(), 'return', remarks: $request->string('remarks')->toString());

        return new LoanResource($loan->fresh()->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    /**
     * first_due_date/maturity_date/schedule are first computed back in
     * computeLoanFields() (store()/update()), anchored on the *application*
     * date — necessary then, since that's the only date available for the
     * eligibility/preview computation while the loan is still being reviewed.
     * But a borrower doesn't start owing payments until the money is actually
     * in hand, and approval can take anywhere from days to weeks — so on
     * release, re-anchor the whole schedule to the real release date instead
     * of leaving it dated from whenever the application was originally typed
     * up. The peso amounts (principal/interest/monthly amortization) are
     * unaffected by which date the schedule starts from, so only the dates
     * are regenerated here — the stored totals stay as computed at
     * application time.
     */
    public function release(LoanReleaseRequest $request, LoanCalculator $calculator, FundLedgerService $fundLedger, Loan $loan)
    {
        $this->authorize('act', [$loan, 'release']);
        $principalBalance = max(0, round((float) $loan->principal, 2));
        $interestBalance = max(0, round((float) $loan->total_interest, 2));

        $releaseDate = now();
        // addMonthNoOverflow(), not addMonth() — see LoanCalculator's docblock: a plain
        // addMonth() from a 29th/30th/31st release date overflows into the following
        // month instead of clamping (e.g. Jan 31 -> Mar 3, skipping February entirely).
        $firstDueDate = $releaseDate->copy()->addMonthNoOverflow();
        $recomputed = $calculator->compute(
            (float) $loan->principal,
            (float) $loan->interest_rate,
            (int) $loan->term_months,
            (float) $loan->processing_fee,
            $loan->loanType->interest_method,
            $firstDueDate,
            $loan->loanType->service_charge_percent !== null ? (float) $loan->loanType->service_charge_percent : null,
        );

        DB::transaction(function () use ($request, $loan, $recomputed, $firstDueDate, $releaseDate, $principalBalance, $interestBalance, $fundLedger) {
            $loan->schedule()->delete();
            foreach ($recomputed['schedule'] as $entry) {
                $loan->schedule()->create($entry);
            }

            $this->workflow->act($loan, $request->user(), 'release', [
                'release_date' => $releaseDate,
                'release_reference_number' => $request->string('releaseReferenceNumber')->toString(),
                'release_method' => $request->string('releaseMethod')->toString(),
                'actual_released_amount' => $request->input('actualReleasedAmount'),
                'release_remarks' => $request->input('releaseRemarks'),
                'principal_balance' => $principalBalance,
                'interest_balance' => $interestBalance,
                'outstanding_balance' => round($principalBalance + $interestBalance, 2),
                'first_due_date' => $firstDueDate,
                'maturity_date' => $recomputed['maturityDate'],
            ], $request->input('remarks'));
            $fundLedger->recordLoanRelease($loan, (float) $request->input('actualReleasedAmount'), $request->user());
        });

        return new LoanResource($loan->fresh()->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    public function store(LoanRequest $request, LoanCalculator $calculator, LoanEligibilityService $eligibilityService, LoanIncomeBracketService $bracketService, ReloanEligibilityService $reloanEligibilityService)
    {
        if (! $request->user()->hasPermission('loans.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.create')) {
            abort(403, "You don't have permission to save drafts.");
        }

        // A member self-service account can only ever apply for themselves —
        // never trust a client-submitted memberId for one of these accounts.
        $member = Member::findOrFail($request->user()->member_id ?? $request->input('memberId'));
        // store() is only ever used for brand-new applications — reloans are
        // created via ReloanController::createDraft() and edited through
        // update() below, never through here.
        $computed = $this->computeLoanFields($request, $calculator, $eligibilityService, $bracketService, $reloanEligibilityService, $member);
        $this->guardOverride($request, $computed['columns']['eligibility']);

        $loan = DB::transaction(function () use ($request, $member, $asDraft, $computed) {
            $loan = Loan::create([
                'application_date' => $computed['applicationDate'],
                'member_id' => $member->id,
                ...$computed['columns'],
                'application_type' => 'new',
                'status' => $asDraft ? 'Draft' : 'Submitted',
                'draft_current_step' => $request->integer('draftCurrentStep', 1),
                'assigned_officer' => $request->input('assignedOfficer'),
                'requirements' => $request->input('requirements', []),
                'created_by' => $request->user()->full_name,
            ]);
            $loan->update(['application_number' => app(DocumentNumberService::class)->generate('loan', $loan->id, $computed['applicationDate'])]);

            foreach ($computed['schedule'] as $entry) {
                $loan->schedule()->create($entry);
            }

            if ($asDraft) {
                $this->workflow->recordDraftAction($loan, $request->user(), 'draft_created');
            } else {
                $this->recordOverrideIfRequested($request, $loan);
                $this->workflow->startInstance($loan, 'loan_application', $request->user());
            }

            return $loan;
        });

        return new LoanResource($loan->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    /**
     * Edits a draft loan in place — never available once it has moved past
     * Draft status (the approval workflow, not this endpoint, owns it then).
     */
    public function update(LoanRequest $request, LoanCalculator $calculator, LoanEligibilityService $eligibilityService, LoanIncomeBracketService $bracketService, ReloanEligibilityService $reloanEligibilityService, Loan $loan)
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
        $computed = $this->computeLoanFields($request, $calculator, $eligibilityService, $bracketService, $reloanEligibilityService, $member, $loan);
        $this->guardOverride($request, $computed['columns']['eligibility']);

        DB::transaction(function () use ($request, $member, $asDraft, $computed, $loan) {
            $loan->update([
                'member_id' => $member->id,
                ...$computed['columns'],
                'status' => $asDraft ? 'Draft' : 'Submitted',
                'draft_current_step' => $request->integer('draftCurrentStep', $loan->draft_current_step ?? 1),
                'assigned_officer' => $request->input('assignedOfficer', $loan->assigned_officer),
                'requirements' => $request->input('requirements', []),
            ]);

            $loan->schedule()->delete();
            foreach ($computed['schedule'] as $entry) {
                $loan->schedule()->create($entry);
            }

            if ($asDraft) {
                $this->workflow->recordDraftAction($loan, $request->user(), 'draft_updated');
            } else {
                $this->recordOverrideIfRequested($request, $loan);
                $this->workflow->startInstance($loan, 'loan_application', $request->user());
            }
        });

        return new LoanResource($loan->load(['member.office', 'loanType', 'previousLoan', 'documents']));
    }

    /**
     * Permanently removes a draft loan application (and its computed
     * amortization schedule, if any was generated). Only ever available for
     * status === 'Draft' — a submitted/finalized loan is never deletable
     * here. `drafts.delete_own` covers the encoder's own drafts;
     * `drafts.delete_all` allows deleting anyone's.
     */
    public function destroy(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Draft') {
            abort(403, 'Only draft loan applications can be deleted.');
        }

        $isOwn = $loan->created_by === $request->user()->full_name;
        $allowed = ($isOwn && $request->user()->hasPermission('drafts.delete_own'))
            || $request->user()->hasPermission('drafts.delete_all');

        if (! $allowed) {
            abort(403, "You don't have permission to perform this action.");
        }

        DB::transaction(function () use ($loan, $request) {
            $this->auditLog->record($request->user(), $loan, 'draft_deleted');
            $loan->schedule()->delete();
            $loan->delete();
        });

        return response()->json(['message' => 'Draft deleted successfully.']);
    }

    /**
     * A failed critical check (e.g. the 6-month minimum) can only be
     * overridden by someone holding loans.override_eligibility, with a
     * written reason — enforced regardless of what the client sends.
     *
     * @param  array<int, array{label: string, passed: bool, detail: string}>  $eligibility
     */
    private function guardOverride(Request $request, array $eligibility): void
    {
        // Drafts intentionally preserve incomplete or currently ineligible
        // applications for later completion. Eligibility is enforced only
        // when the user performs the final submission.
        if ($request->boolean('asDraft')) {
            return;
        }

        $failedChecks = collect($eligibility)->filter(fn ($c) => ! $c['passed']);
        if ($failedChecks->isEmpty()) {
            return;
        }

        if ($failedChecks->contains(fn ($c) => $c['label'] === 'Fully Paid Monthly Dues')) {
            abort(422, $failedChecks->firstWhere('label', 'Fully Paid Monthly Dues')['detail']);
        }

        if (! $request->boolean('eligibilityOverridden')) {
            abort(422, $failedChecks->first()['detail']);
        }

        if (! LoanSetting::current()->allow_eligibility_override) {
            abort(422, 'Loan eligibility overrides are disabled in Loan Settings.');
        }
        if (! $request->user()->hasPermission('loans.override_eligibility')) {
            abort(403, "You don't have permission to override eligibility checks.");
        }
        if (! $request->filled('eligibilityOverrideReason')) {
            abort(422, 'An override reason is required.');
        }
    }

    private function recordOverrideIfRequested(Request $request, Loan $loan): void
    {
        if (! $request->boolean('eligibilityOverridden') || ! $request->filled('eligibilityOverrideReason')) {
            return;
        }

        LoanEligibilityException::create([
            'loan_id' => $loan->id,
            'exception_type' => $loan->application_type === 'reloan' ? 'reloan_eligibility' : 'loan_eligibility',
            'reason' => $request->string('eligibilityOverrideReason')->toString(),
            'board_resolution_reference' => $request->input('boardResolutionReference'),
            'board_resolution_document_path' => $request->input('boardResolutionDocumentPath'),
            'granted_by' => $request->user()->id,
            'granted_at' => now(),
        ]);

        $this->auditLog->record(
            $request->user(),
            $loan,
            $loan->application_type === 'reloan' ? 'reloan_override_applied' : 'eligibility_override_applied',
            $request->string('eligibilityOverrideReason')->toString()
        );
    }

    /**
     * Shared compute step for store()/update() — returns null-safe column
     * values plus a schedule array (empty when the loan isn't fully
     * specified yet, which is expected for an early-stage draft).
     *
     * @return array{applicationDate: Carbon, columns: array<string, mixed>, schedule: array<int, array<string, mixed>>}
     */
    private function computeLoanFields(
        LoanRequest $request,
        LoanCalculator $calculator,
        LoanEligibilityService $eligibilityService,
        LoanIncomeBracketService $bracketService,
        ReloanEligibilityService $reloanEligibilityService,
        Member $member,
        ?Loan $existingLoan = null
    ): array {
        $applicationDate = now();
        // addMonthNoOverflow() — see the note in release() above.
        $firstDueDate = $applicationDate->copy()->addMonthNoOverflow();
        $isReloan = $existingLoan?->application_type === 'reloan';

        $loanTypeId = $request->input('loanTypeId');
        $loanType = $loanTypeId ? LoanType::find($loanTypeId) : null;
        $requestedAmount = $request->filled('requestedAmount') ? (float) $request->input('requestedAmount') : null;
        $termMonths = $request->filled('termMonths') ? (int) $request->input('termMonths') : null;

        if ($loanType && $termMonths !== null && $termMonths > (int) $loanType->max_term_months) {
            throw ValidationException::withMessages([
                'termMonths' => [
                    "Loan term cannot exceed the configured maximum of {$loanType->max_term_months} month(s) for {$loanType->name}.",
                ],
            ]);
        }

        // A member's first Solidarity Cash Assistance Loan is fixed by the
        // resolution at ₱20,000. This server-side rule is authoritative so a
        // manually altered browser/API request cannot exceed the limit.
        $isFirstSolidarityLoan = $loanType
            && $eligibilityService->isFirstSolidarityLoan($member, $loanType);
        if ($isFirstSolidarityLoan) {
            $firstLoanAmount = (float) LoanSetting::current()->first_solidarity_loan_amount;
            if ($requestedAmount !== null
                && abs($requestedAmount - $firstLoanAmount) >= 0.01) {
                throw ValidationException::withMessages([
                    'requestedAmount' => [
                        'A first-time Solidarity borrower may apply for exactly ₱'.number_format($firstLoanAmount, 2).' only.',
                    ],
                ]);
            }

            if ($requestedAmount !== null) {
                $requestedAmount = $firstLoanAmount;
            }
        }

        // Income-bracketed loan types (Resolution No. 24-2026, Solidarity Cash
        // Assistance Loan) cap the loanable amount by the member's net pay —
        // authoritative, overrides whatever the client sent.
        if ($loanType && ! $isFirstSolidarityLoan && $loanType->incomeBrackets->isNotEmpty()) {
            $bracket = $member->net_pay !== null ? $bracketService->bracketFor($loanType, (float) $member->net_pay) : null;
            if (! $bracket) {
                $requestedAmount = null;
            } elseif ($requestedAmount !== null
                && ($requestedAmount < (float) $loanType->min_amount || $requestedAmount > (float) $bracket->loanable_amount)) {
                throw ValidationException::withMessages([
                    'requestedAmount' => [
                        "Requested amount must be between {$loanType->min_amount} and {$bracket->loanable_amount} for the member's net-pay bracket.",
                    ],
                ]);
            }
        }

        $computation = null;
        $eligibility = [];
        $previousObligation = null;
        if ($loanType && $requestedAmount && $termMonths) {
            $computation = $calculator->compute(
                $requestedAmount,
                (float) $loanType->default_interest_rate,
                $termMonths,
                (float) $loanType->processing_fee,
                $loanType->interest_method,
                $firstDueDate,
                $loanType->service_charge_percent !== null ? (float) $loanType->service_charge_percent : null,
            );

            if ($isReloan && $existingLoan->previousLoan) {
                $result = $reloanEligibilityService->canCreateReloan($existingLoan->previousLoan, $member, $loanType, $requestedAmount, $termMonths);
                $eligibility = $result['checks'];
                $previousObligation = $result['previousObligation'];
            } else {
                $eligibility = $eligibilityService->evaluate($member, $loanType, $requestedAmount, $termMonths);
            }
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
                'eligibility_overridden' => $request->boolean('eligibilityOverridden'),
                'eligibility_override_reason' => $request->input('eligibilityOverrideReason'),
                'current_net_take_home_pay' => $isReloan ? $request->input('currentNetTakeHomePay') : null,
                'reloan_policy_snapshot' => $isReloan ? LoanSetting::current()->reloanPolicySnapshot() : null,
                'previous_obligation_amount' => $previousObligation['amount'] ?? null,
                'previous_obligation_settlement_method' => $previousObligation['settlementMethod'] ?? null,
            ],
            'schedule' => $computation['schedule'] ?? [],
        ];
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        // A member self-service account (User::member_id set) is hard-scoped to
        // its own records — this overrides any office/search/status filter the
        // client sends, and applies regardless of what role/permissions the
        // account otherwise carries.
        if ($memberId = $request->user()?->member_id) {
            $query->where('member_id', $memberId);
        }

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
        if ($request->boolean('activeOnly')) {
            $query->whereIn('status', ['Released', 'Active', 'Overdue', 'Restructured']);
        }
        if ($applicationType = $request->string('applicationType')->toString()) {
            $query->where('application_type', $applicationType);
        }
    }
}
