<?php

namespace App\Http\Controllers;

use App\Http\Requests\BenefitTypeRequest;
use App\Http\Resources\BenefitTypeResource;
use App\Models\BenefitType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BenefitTypeController extends Controller
{
    /**
     * Viewing benefit types requires no permission — visible to any
     * authenticated user, matching today's frontend which shows them to
     * anyone who can reach the page. Returns a plain array (not paginated)
     * since the frontend consumes this as BenefitType[] directly.
     */
    public function index(): AnonymousResourceCollection
    {
        return BenefitTypeResource::collection(
            BenefitType::with(['prorationTiers', 'fyAmounts'])->orderByDesc('created_at')->get()
        );
    }

    public function store(BenefitTypeRequest $request): BenefitTypeResource
    {
        $this->authorizeBenefitSettings($request);

        $benefitType = BenefitType::create($request->modelAttributes());
        $this->syncProration($benefitType, $request);

        return new BenefitTypeResource($benefitType->load(['prorationTiers', 'fyAmounts']));
    }

    public function update(BenefitTypeRequest $request, BenefitType $benefitType): BenefitTypeResource
    {
        $this->authorizeBenefitSettings($request);

        $benefitType->update($request->modelAttributes());
        $this->syncProration($benefitType, $request);

        return new BenefitTypeResource($benefitType->load(['prorationTiers', 'fyAmounts']));
    }

    /**
     * Full replace-sync of a benefit type's proration tiers and fiscal-year
     * amounts — same "send the whole array every save" convention already
     * used for member beneficiaries.
     */
    private function syncProration(BenefitType $benefitType, BenefitTypeRequest $request): void
    {
        $benefitType->prorationTiers()->delete();
        foreach ($request->prorationTiersInput() as $tier) {
            $benefitType->prorationTiers()->create([
                'min_months' => $tier['minMonths'],
                'max_months' => $tier['maxMonths'] ?? null,
                'percentage' => $tier['percentage'],
            ]);
        }

        $benefitType->fyAmounts()->delete();
        foreach ($request->fyAmountsInput() as $fy) {
            $benefitType->fyAmounts()->create([
                'fiscal_year' => $fy['fiscalYear'] ?? null,
                'base_amount' => $fy['baseAmount'],
            ]);
        }
    }

    public function destroy(Request $request, BenefitType $benefitType): JsonResponse
    {
        $this->authorizeBenefitSettings($request);

        $benefitType->delete();

        return response()->json(['message' => 'Benefit type deleted.']);
    }

    private function authorizeBenefitSettings(Request $request): void
    {
        if (! $request->user()->hasPermission('settings.benefit')) {
            abort(403, "You don't have permission to perform this action.");
        }
    }
}
