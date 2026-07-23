<?php

namespace App\Http\Controllers;

use App\Http\Requests\Member\MemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Services\ApprovalWorkflowService;
use App\Services\LoanEligibilityService;
use App\Services\MembershipApprovalService;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflow,
        private readonly MembershipApprovalService $membershipApproval,
    ) {}

    /**
     * Membership-duration-only eligibility snapshot for the Create Loan
     * Application member selector (no loan type is known yet, so this omits
     * amount/term/contribution-count checks — those run once a loan type is
     * picked, via LoanEligibilityService::evaluate()).
     */
    public function loanEligibility(Request $request, Member $member, LoanEligibilityService $eligibilityService)
    {
        if (! $request->user()->hasPermission('loans.view')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $registration = $eligibilityService->registrationApprovedCheck($member);
        $status = $eligibilityService->activeStatusCheck($member);
        $duration = $eligibilityService->membershipDurationCheck($member);
        $dues = $eligibilityService->contributionStandingCheck($member, 'Monthly Dues');
        $pabaon = $eligibilityService->contributionStandingCheck($member, 'Cash Pabaon');

        $eligible = $registration['passed'] && $status['passed'] && $duration['passed'];

        return response()->json([
            'eligible' => $eligible,
            'completedMonths' => $duration['completed_months'],
            'requiredMonths' => $duration['required_months'],
            'eligibleOn' => $duration['eligible_on'],
            'checks' => [$registration, $status, $duration, $dues, $pabaon],
        ]);
    }

    public function index(Request $request)
    {
        $query = Member::with(['office', 'beneficiaries', 'documents', 'approvalInstance'])
            ->where('is_archived', false)
            ->where('is_draft', $request->boolean('draftsOnly'));

        $this->applyFilters($query, $request);

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, MemberResource::class));
    }

    public function archived(Request $request)
    {
        $query = Member::with(['office', 'beneficiaries', 'documents'])->where('is_archived', true);

        if ($search = $request->string('search')->toString()) {
            $this->applySearch($query, $search);
        }

        $query->orderBy('archived_at', 'desc');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, MemberResource::class));
    }

    public function all()
    {
        // Sync member picklists (bulk contribution entry, etc.) — active, non-archived, non-draft only.
        return MemberResource::collection(
            Member::with(['office', 'beneficiaries', 'documents', 'approvalInstance'])
                ->where('is_archived', false)
                ->where('is_draft', false)
                ->where('membership_status', 'Active')
                ->orderBy('surname')
                ->get()
        );
    }

    public function show(Request $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.view')) {
            abort(403, "You don't have permission to perform this action.");
        }

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents', 'approvalInstance']));
    }

    public function store(MemberRequest $request)
    {
        if (! $request->user()->hasPermission('members.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.create')) {
            abort(403, "You don't have permission to save drafts.");
        }

        $member = DB::transaction(function () use ($request, $asDraft) {
            $member = Member::create([
                ...$this->mapToColumns($request->validated()),
                'is_draft' => $asDraft,
                'draft_current_step' => $request->integer('draftCurrentStep', 1),
                'created_by' => $request->user()->full_name,
                'submitted_by_user_id' => $request->user()->id,
            ]);

            if ($asDraft) {
                $member->update(['draft_reference_no' => 'GCGEA-MEM-DRAFT-'.now()->year.'-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT)]);
            } else {
                $member->update(['member_number' => 'GCGEA-MEM-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT)]);
            }

            $this->syncBeneficiaries($member, $request->input('beneficiaries', []));
            $member->update(['draft_completion_percentage' => $asDraft ? $member->fresh('beneficiaries')->draftCompletionPercentage() : null]);

            if (! $asDraft) {
                $this->membershipApproval->process($member, $request->user(), 'manual');
            }

            return $member;
        });

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents', 'approvalInstance']));
    }

    public function update(MemberRequest $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.update_own')) {
            abort(403, "You don't have permission to save drafts.");
        }

        DB::transaction(function () use ($request, $member, $asDraft) {
            // Only a genuine draft->submitted transition should (re)start the
            // approval workflow — a plain edit of an already-finalized member
            // (the common case: asDraft is absent/false on every ordinary
            // profile save) must never reset an existing approval back to
            // pending. First-time draft submissions normally go through the
            // dedicated submit() endpoint below, not this one, but this guard
            // keeps update() correct on its own terms regardless of caller.
            $wasDraft = $member->is_draft;

            $member->update([
                ...$this->mapToColumns($request->validated()),
                'is_draft' => $asDraft,
                'draft_current_step' => $request->integer('draftCurrentStep', $member->draft_current_step ?? 1),
            ]);
            $this->syncBeneficiaries($member, $request->input('beneficiaries', []));
            $member->update([
                'draft_completion_percentage' => $asDraft ? $member->fresh('beneficiaries')->draftCompletionPercentage() : null,
                'draft_reference_no' => $asDraft ? ($member->draft_reference_no ?? 'GCGEA-MEM-DRAFT-'.now()->year.'-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT)) : null,
            ]);

            if ($wasDraft && ! $asDraft) {
                $this->membershipApproval->process($member, $request->user(), 'manual');
            }
        });

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents', 'approvalInstance']));
    }

    public function updateMembershipStatus(Request $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $validated = $request->validate([
            'membershipStatus' => ['required', 'in:Active,Inactive'],
        ]);

        $member->update(['membership_status' => $validated['membershipStatus']]);

        return new MemberResource($member->fresh()->load(['office', 'beneficiaries', 'documents', 'approvalInstance']));
    }

    /**
     * Finalizes a draft member into a real registration — full strict
     * validation, assigns the real member_number, clears draft bookkeeping.
     * Reuses MemberRequest, which validates strictly whenever `asDraft` is
     * absent/false in the submitted payload.
     */
    public function submit(MemberRequest $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.create')) {
            abort(403, "You don't have permission to perform this action.");
        }
        if (! $request->user()->hasPermission('drafts.submit')) {
            abort(403, "You don't have permission to submit drafts.");
        }

        DB::transaction(function () use ($request, $member) {
            $member->update([
                ...$this->mapToColumns($request->validated()),
                'is_draft' => false,
                'draft_reference_no' => null,
                'draft_completion_percentage' => null,
                'draft_current_step' => null,
            ]);

            if (! $member->member_number) {
                $member->update(['member_number' => 'GCGEA-MEM-'.str_pad((string) $member->id, 6, '0', STR_PAD_LEFT)]);
            }

            $this->syncBeneficiaries($member, $request->input('beneficiaries', []));

            $member->update(['submitted_by_user_id' => $request->user()->id]);
            $this->membershipApproval->process($member, $request->user(), 'manual');
        });

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents', 'approvalInstance']));
    }

    public function approve(Request $request, Member $member)
    {
        $this->authorize('act', [$member, 'approve']);
        $this->membershipApproval->assertMayApproveOwn($member, $request->user());

        // Imported members start life with membership_status='Pending'
        // (never touched by the workflow engine by default, since that
        // field is otherwise encoder-owned) — approval is what actually
        // activates them. Manual registrations don't carry an imported
        // batch id, so their approve behavior is unchanged.
        $extra = $this->membershipApproval->markApproved($member, $request->user());

        $this->workflow->act($member, $request->user(), 'approve', $extra, $request->input('remarks'));

        return new MemberResource($member->fresh()->load(['office', 'beneficiaries', 'documents', 'approvalInstance']));
    }

    public function reject(Request $request, Member $member)
    {
        $request->validate(['remarks' => ['required', 'string']]);
        $this->authorize('act', [$member, 'reject']);
        $this->workflow->act($member, $request->user(), 'reject', remarks: $request->string('remarks')->toString());

        return new MemberResource($member->fresh()->load(['office', 'beneficiaries', 'documents', 'approvalInstance']));
    }

    public function archive(Request $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.archive')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $request->validate(['reason' => ['required', 'string']]);

        $member->update([
            'is_archived' => true,
            'archived_at' => now(),
            'archived_reason' => $request->string('reason')->toString(),
        ]);

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents']));
    }

    public function restore(Request $request, Member $member)
    {
        if (! $request->user()->hasPermission('members.restore')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $member->update(['is_archived' => false, 'archived_at' => null, 'archived_reason' => null]);

        return new MemberResource($member->load(['office', 'beneficiaries', 'documents']));
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = $request->string('search')->toString()) {
            $this->applySearch($query, $search);
        }
        if ($office = $request->string('office')->toString()) {
            $query->whereHas('office', fn ($q) => $q->where('name', $office));
        }
        if ($sex = $request->string('sex')->toString()) {
            $query->where('sex', $sex);
        }
        if ($status = $request->string('membershipStatus')->toString()) {
            $query->where('membership_status', $status);
        }
        if ($retiree = $request->string('retireeStatus')->toString()) {
            $query->where('retiree_status', $retiree);
        }
        if ($request->boolean('incompleteOnly')) {
            $query->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '')
                    ->orWhereNull('cellphone_number')->orWhere('cellphone_number', '')
                    ->orWhereNull('permanent_address')->orWhere('permanent_address', '')
                    ->orWhereDoesntHave('beneficiaries')
                    ->orWhereDoesntHave('documents');
            });
        }
    }

    private function applySearch(Builder $query, string $term): void
    {
        $query->where(function ($q) use ($term) {
            $q->where('surname', 'ilike', "%{$term}%")
                ->orWhere('first_name', 'ilike', "%{$term}%")
                ->orWhere('member_number', 'ilike', "%{$term}%")
                ->orWhere('position', 'ilike', "%{$term}%")
                ->orWhere('cellphone_number', 'ilike', "%{$term}%")
                ->orWhereHas('office', fn ($oq) => $oq->where('name', 'ilike', "%{$term}%"));
        });
    }

    /**
     * Null-safe by design: draft payloads may omit most fields entirely
     * (MemberRequest only requires a name when `asDraft` is true), so every
     * key falls back to null/existing-column-default rather than erroring.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapToColumns(array $validated): array
    {
        return [
            'employee_number' => $validated['employeeNumber'] ?? null,
            'surname' => $validated['surname'] ?? null,
            'first_name' => $validated['firstName'] ?? null,
            'middle_name' => $validated['middleName'] ?? null,
            'suffix' => $validated['suffix'] ?? null,
            'sex' => $validated['sex'] ?? null,
            'birthdate' => $validated['birthdate'] ?? null,
            'civil_status' => $validated['civilStatus'] ?? null,
            'permanent_address' => $validated['permanentAddress'] ?? null,
            'cellphone_number' => $validated['cellphoneNumber'] ?? null,
            'email' => $validated['email'] ?? null,
            'name_of_spouse' => $validated['nameOfSpouse'] ?? null,
            'office_id' => $validated['officeId'] ?? null,
            'position' => $validated['position'] ?? null,
            'date_of_regular_appointment' => $validated['dateOfRegularAppointment'] ?? null,
            'employment_status' => $validated['employmentStatus'] ?? null,
            'membership_type' => $validated['membershipType'] ?? null,
            'membership_date' => $validated['membershipDate'] ?? null,
            'membership_status' => $validated['membershipStatus'] ?? null,
            'net_pay' => $validated['netPay'] ?? null,
            'retiree_status' => $validated['retireeStatus'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
        ];
    }

    /**
     * Full replace-sync of a member's beneficiaries — matches the frontend's
     * existing behavior of sending the whole beneficiaries array on every save.
     *
     * @param  array<int, array<string, mixed>>  $beneficiaries
     */
    private function syncBeneficiaries(Member $member, array $beneficiaries): void
    {
        $keepIds = [];

        foreach ($beneficiaries as $beneficiary) {
            $row = $member->beneficiaries()->updateOrCreate(
                ['id' => $beneficiary['id'] ?? null],
                [
                    'full_name' => $beneficiary['fullName'],
                    'relationship' => $beneficiary['relationship'],
                    'birthdate' => $beneficiary['birthdate'],
                    'contact_number' => $beneficiary['contactNumber'] ?? null,
                    'address' => $beneficiary['address'] ?? null,
                    'share_percentage' => $beneficiary['sharePercentage'] ?? null,
                ]
            );
            $keepIds[] = $row->id;
        }

        $member->beneficiaries()->whereNotIn('id', $keepIds)->delete();
    }
}
