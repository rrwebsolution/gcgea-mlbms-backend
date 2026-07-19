<?php

namespace App\Http\Controllers;

use App\Http\Requests\Benefit\BenefitRequest;
use App\Http\Resources\BenefitApplicationResource;
use App\Models\BenefitApplication;
use App\Models\BenefitType;
use App\Models\Member;
use App\Services\BenefitEligibilityService;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BenefitApplicationController extends Controller
{
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

    public function show(BenefitApplication $benefit)
    {
        return new BenefitApplicationResource($benefit->load(['member.office', 'benefitType']));
    }

    public function store(BenefitRequest $request, BenefitEligibilityService $eligibilityService)
    {
        if (! $request->user()->hasPermission('benefits.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $asDraft = $request->boolean('asDraft');
        if ($asDraft && ! $request->user()->hasPermission('drafts.create')) {
            abort(403, "You don't have permission to save drafts.");
        }

        $member = Member::findOrFail($request->input('memberId'));
        $computed = $this->computeBenefitFields($request, $eligibilityService, $member);

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
            $benefit->update(['application_number' => 'GCGEA-BEN-'.$computed['applicationDate']->year.'-'.str_pad((string) $benefit->id, 6, '0', STR_PAD_LEFT)]);

            return $benefit;
        });

        return new BenefitApplicationResource($benefit->load(['member.office', 'benefitType']));
    }

    /**
     * Edits a draft application in place — never available once it has
     * moved past Draft status (the approval workflow owns it then).
     */
    public function update(BenefitRequest $request, BenefitEligibilityService $eligibilityService, BenefitApplication $benefit)
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
        $computed = $this->computeBenefitFields($request, $eligibilityService, $member);

        DB::transaction(function () use ($request, $member, $asDraft, $computed, $benefit) {
            $benefit->update([
                'member_id' => $member->id,
                ...$computed['columns'],
                'requirements' => $request->input('requirements', []),
                'status' => $asDraft ? 'Draft' : 'Submitted',
                'draft_current_step' => $request->integer('draftCurrentStep', $benefit->draft_current_step ?? 1),
            ]);
        });

        return new BenefitApplicationResource($benefit->load(['member.office', 'benefitType']));
    }

    /**
     * Shared compute step for store()/update() — null-safe when the benefit
     * type/amount aren't chosen yet (expected for an early-stage draft).
     *
     * @return array{applicationDate: \Illuminate\Support\Carbon, columns: array<string, mixed>}
     */
    private function computeBenefitFields(BenefitRequest $request, BenefitEligibilityService $eligibilityService, Member $member): array
    {
        $benefitTypeId = $request->input('benefitTypeId');
        $benefitType = $benefitTypeId ? BenefitType::find($benefitTypeId) : null;
        $requestedAmount = $request->filled('requestedAmount') ? (float) $request->input('requestedAmount') : null;

        $eligibility = [];
        $eligibilityResult = null;
        if ($benefitType && $requestedAmount) {
            $eligibility = $eligibilityService->evaluate($member, $benefitType, $requestedAmount);
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
        if ($benefitTypeId = $request->string('benefitTypeId')->toString()) {
            $query->where('benefit_type_id', $benefitTypeId);
        }
    }
}
