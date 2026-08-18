<?php

namespace App\Http\Controllers;

use App\Http\Requests\Benefit\BenefitRequest;
use App\Http\Resources\BenefitApplicationResource;
use App\Models\BenefitApplication;
use App\Models\BenefitType;
use App\Models\Member;
use App\Models\SystemSetting;
use App\Services\ApprovalWorkflowService;
use App\Services\AuditLogService;
use App\Services\BenefitEligibilityService;
use App\Services\BenefitProrationService;
use App\Services\DocumentNumberService;
use App\Services\FundLedgerService;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BenefitApplicationController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflow,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request)
    {
        $query = BenefitApplication::with(['member.office', 'benefitType']);
        $this->applyFilters($query, $request);
        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, BenefitApplicationResource::class));
    }

    public function all(Request $request)
    {
        $query = BenefitApplication::with(['member.office', 'benefitType']);
        $this->applyFilters($query, $request);

        return BenefitApplicationResource::collection($query->orderBy('application_date', 'desc')->get());
    }

    public function show(Request $request, BenefitApplication $benefit)
    {
        $this->guardMemberScope($request, $benefit);

        return new BenefitApplicationResource($benefit->load(['member.office', 'benefitType', 'documents']));
    }

    /**
     * Blocks a member self-service account from reading a benefit application
     * that isn't their own by ID, even though route-model binding already
     * resolved it.
     */
    private function guardMemberScope(Request $request, BenefitApplication $benefit): void
    {
        $memberId = $request->user()?->member_id;
        if ($memberId && $benefit->member_id !== $memberId) {
            abort(403, "You don't have permission to perform this action.");
        }
    }

    public function review(Request $request, BenefitApplication $benefit)
    {
        $this->authorize('act', [$benefit, 'review']);
        $this->workflow->act($benefit, $request->user(), 'review', remarks: $request->input('remarks'));

        return new BenefitApplicationResource($benefit->fresh()->load(['member.office', 'benefitType']));
    }

    public function approve(Request $request, BenefitApplication $benefit)
    {
        $this->authorize('act', [$benefit, 'approve']);
        $this->workflow->act($benefit, $request->user(), 'approve', remarks: $request->input('remarks'));

        return new BenefitApplicationResource($benefit->fresh()->load(['member.office', 'benefitType']));
    }

    public function reject(Request $request, BenefitApplication $benefit)
    {
        $request->validate(['remarks' => ['required', 'string']]);
        $this->authorize('act', [$benefit, 'reject']);
        $this->workflow->act($benefit, $request->user(), 'reject', remarks: $request->string('remarks')->toString());

        return new BenefitApplicationResource($benefit->fresh()->load(['member.office', 'benefitType']));
    }

    public function returnForRevision(Request $request, BenefitApplication $benefit)
    {
        $request->validate(['remarks' => ['required', 'string']]);
        $this->authorize('act', [$benefit, 'return']);
        $this->workflow->act($benefit, $request->user(), 'return', remarks: $request->string('remarks')->toString());

        return new BenefitApplicationResource($benefit->fresh()->load(['member.office', 'benefitType']));
    }

    public function release(Request $request, FundLedgerService $fundLedger, BenefitApplication $benefit)
    {
        $this->authorize('act', [$benefit, 'release']);

        $data = $request->validate([
            'actualReleasedAmount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $releasedAmount = (float) ($data['actualReleasedAmount'] ?? $benefit->approved_amount ?? $benefit->requested_amount);
        DB::transaction(function () use ($benefit, $request, $data, $releasedAmount, $fundLedger) {
            $this->workflow->act($benefit, $request->user(), 'release', [
                'release_date' => now(),
                'release_reference_number' => app(DocumentNumberService::class)->generate('benefitRelease', $benefit->id),
                'actual_released_amount' => $releasedAmount,
            ], $data['remarks'] ?? null);
            $fundLedger->recordBenefitRelease($benefit, $releasedAmount, $request->user());
        });

        return new BenefitApplicationResource($benefit->fresh()->load(['member.office', 'benefitType']));
    }

    public function store(BenefitRequest $request, BenefitEligibilityService $eligibilityService, BenefitProrationService $prorationService)
    {
        if (! $request->user()->hasPermission('benefits.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.create')) {
            abort(403, "You don't have permission to save drafts.");
        }

        // A member self-service account can only ever apply for themselves —
        // never trust a client-submitted memberId for one of these accounts.
        $member = Member::findOrFail($request->user()->member_id ?? $request->input('memberId'));
        $this->assertRetirementBenefitAllowed($request, $member, $asDraft);
        $computed = $this->computeBenefitFields($request, $eligibilityService, $prorationService, $member);
        $this->validateSubmissionPolicy($request, $computed);

        $benefit = DB::transaction(function () use ($request, $member, $asDraft, $computed) {
            $benefit = BenefitApplication::create([
                'application_date' => $computed['applicationDate'],
                'member_id' => $member->id,
                ...$computed['columns'],
                'requirements' => $request->input('requirements', []),
                'status' => $asDraft ? 'Draft' : 'Submitted',
                'draft_current_step' => $request->integer('draftCurrentStep', 1),
                'created_by' => $request->user()->full_name,
            ]);
            $benefit->update(['application_number' => app(DocumentNumberService::class)->generate('benefit', $benefit->id, $computed['applicationDate'])]);

            if ($asDraft) {
                $this->workflow->recordDraftAction($benefit, $request->user(), 'draft_created');
            } else {
                $this->workflow->startInstance($benefit, 'benefit_application', $request->user());
            }

            return $benefit;
        });

        return new BenefitApplicationResource($benefit->load(['member.office', 'benefitType']));
    }

    /**
     * Edits a draft application in place — never available once it has
     * moved past Draft status (the approval workflow owns it then).
     */
    public function update(BenefitRequest $request, BenefitEligibilityService $eligibilityService, BenefitProrationService $prorationService, BenefitApplication $benefit)
    {
        if (! $request->user()->hasPermission('benefits.update')) {
            abort(403, "You don't have permission to perform this action.");
        }
        if ($benefit->status !== 'Draft') {
            abort(403, 'Only draft benefit applications can be edited directly.');
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.update_own')) {
            abort(403, "You don't have permission to save drafts.");
        }

        $member = Member::findOrFail($request->input('memberId'));
        $this->assertRetirementBenefitAllowed($request, $member, $asDraft);
        $computed = $this->computeBenefitFields($request, $eligibilityService, $prorationService, $member);
        $this->validateSubmissionPolicy($request, $computed);

        DB::transaction(function () use ($request, $member, $asDraft, $computed, $benefit) {
            $benefit->update([
                'member_id' => $member->id,
                ...$computed['columns'],
                'requirements' => $request->input('requirements', []),
                'status' => $asDraft ? 'Draft' : 'Submitted',
                'draft_current_step' => $request->integer('draftCurrentStep', $benefit->draft_current_step ?? 1),
            ]);

            if ($asDraft) {
                $this->workflow->recordDraftAction($benefit, $request->user(), 'draft_updated');
            } else {
                $this->workflow->startInstance($benefit, 'benefit_application', $request->user());
            }
        });

        return new BenefitApplicationResource($benefit->load(['member.office', 'benefitType']));
    }

    public function destroy(Request $request, BenefitApplication $benefit)
    {
        if ($benefit->status !== 'Draft') {
            abort(403, 'Only draft benefit applications can be deleted.');
        }

        $isOwn = $benefit->created_by === $request->user()->full_name;
        $allowed = ($isOwn && $request->user()->hasPermission('drafts.delete_own'))
            || $request->user()->hasPermission('drafts.delete_all');

        if (! $allowed) {
            abort(403, "You don't have permission to perform this action.");
        }

        DB::transaction(function () use ($benefit, $request) {
            $this->auditLog->record($request->user(), $benefit, 'draft_deleted');
            $benefit->delete();
        });

        return response()->json(['message' => 'Draft deleted successfully.']);
    }

    /**
     * Shared compute step for store()/update() — null-safe when the benefit
     * type/amount aren't chosen yet (expected for an early-stage draft).
     *
     * @return array{applicationDate: Carbon, columns: array<string, mixed>}
     */
    private function computeBenefitFields(BenefitRequest $request, BenefitEligibilityService $eligibilityService, BenefitProrationService $prorationService, Member $member): array
    {
        $benefitTypeId = $request->input('benefitTypeId');
        $benefitType = $benefitTypeId ? BenefitType::find($benefitTypeId) : null;
        $requestedAmount = $request->filled('requestedAmount') ? (float) $request->input('requestedAmount') : null;

        // Server-computed and authoritative for prorated benefit types (Resolution
        // No. 24-2026) — overrides whatever the client sent, since the amount
        // depends on the member's contribution history, not free entry.
        if ($benefitType) {
            $prorated = $prorationService->computeAmount($benefitType, $member, $request->integer('fiscalYear') ?: null);
            if ($prorated['amount'] !== null) {
                $requestedAmount = $prorated['amount'];
            }
        }

        $eligibility = [];
        $eligibilityResult = null;
        if ($benefitType && $requestedAmount) {
            $eligibility = $eligibilityService->evaluate($member, $benefitType, $requestedAmount, $request->input('beneficiaryOrRecipient'));
            $eligibilityResult = $eligibilityService->resultFor($eligibility);
        }

        return [
            'applicationDate' => now(),
            'columns' => [
                'benefit_type_id' => $benefitType?->id,
                'requested_amount' => $requestedAmount,
                'reason' => $request->input('reason'),
                'incident_date' => $request->input('incidentDate'),
                'beneficiary_or_recipient' => $request->input('beneficiaryOrRecipient'),
                'eligibility' => $eligibility,
                'eligibility_result' => $eligibilityResult,
            ],
        ];
    }

    private function assertRetirementBenefitAllowed(BenefitRequest $request, Member $member, bool $asDraft): void
    {
        if ($asDraft || ! (SystemSetting::benefit()['requireRetiredStatusForRetirementBenefit'] ?? true)) {
            return;
        }

        $benefitType = BenefitType::find($request->input('benefitTypeId'));
        if ($benefitType?->name === 'Retirement and Separation Benefit' && $member->retiree_status !== 'Retired') {
            abort(422, 'Retirement and Separation Benefit requires the member Retiree Status to be Retired.');
        }
    }

    private function validateSubmissionPolicy(BenefitRequest $request, array &$computed): void
    {
        if ($request->boolean('asDraft')) {
            return;
        }

        $settings = SystemSetting::benefit();
        $requirements = collect($request->input('requirements', []));
        if ($settings['requireSupportingDocuments'] && $requirements->contains(fn ($item) => ! ($item['completed'] ?? false))) {
            abort(422, 'Complete all required supporting documents before submitting this benefit application.');
        }

        if (($computed['columns']['eligibility_result'] ?? null) !== 'Not Eligible') {
            return;
        }

        $canOverride = $settings['allowEligibilityOverride']
            && $request->boolean('overrideEligibility')
            && $request->user()->hasPermission('benefits.override_eligibility')
            && filled($request->input('overrideReason'));

        if (! $canOverride) {
            abort(422, 'This benefit application is not eligible and cannot be submitted without an authorized override.');
        }

        $computed['columns']['eligibility_result'] = 'Eligible with Override';
        $computed['columns']['remarks'] = 'Eligibility override: '.$request->string('overrideReason')->toString();
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        // A member self-service account (User::member_id set) is hard-scoped to
        // its own records — this overrides any status/type filter the client
        // sends, and applies regardless of what role/permissions the account
        // otherwise carries.
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
        if ($benefitTypeId = $request->string('benefitTypeId')->toString()) {
            $query->where('benefit_type_id', $benefitTypeId);
        }
    }
}
