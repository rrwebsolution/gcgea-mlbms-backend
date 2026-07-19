<?php

namespace Database\Seeders;

use App\Models\BenefitApplication;
use App\Models\BenefitType;
use App\Models\Member;
use App\Services\BenefitEligibilityService;
use Illuminate\Database\Seeder;

/**
 * Generates a representative spread of benefit applications (not a literal
 * transcription of src/services/mock-data/benefits.ts) across a handful of
 * seeded members/benefit types/statuses.
 */
class BenefitApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $eligibilityService = app(BenefitEligibilityService::class);

        $members = Member::where('membership_status', 'Active')->where('is_archived', false)->orderBy('id')->get();
        $benefitTypes = BenefitType::where('status', 'Active')->get();

        if ($members->isEmpty() || $benefitTypes->isEmpty()) {
            return;
        }

        BenefitApplication::query()->delete();

        // [memberOffset, benefitTypeOffset, monthsAgo, status]
        $plans = [
            [0, 0, 4, 'Released'],
            [1, 1, 6, 'Completed'],
            [2, 2, 0, 'Submitted'],
            [3, 0, 1, 'Approved'],
            [4, 3, 8, 'Released'],
            [5, 4, 0, 'Draft'],
            [6, 1, 2, 'Rejected'],
            [7, 5, 3, 'Released'],
            [8, 6, 0, 'Under Review'],
            [9, 2, 5, 'Completed'],
        ];

        foreach ($plans as [$memberOffset, $typeOffset, $monthsAgo, $status]) {
            $member = $members->get($memberOffset % $members->count());
            $benefitType = $benefitTypes->get($typeOffset % $benefitTypes->count());
            if (! $member || ! $benefitType) {
                continue;
            }

            $requestedAmount = min($benefitType->maximum_amount, $benefitType->default_amount);
            $applicationDate = now()->subMonths($monthsAgo);

            $eligibility = $eligibilityService->evaluate($member, $benefitType, (float) $requestedAmount);
            $eligibilityResult = $eligibilityService->resultFor($eligibility);
            $isReleased = in_array($status, ['Released', 'Completed'], true);

            $benefit = BenefitApplication::create([
                'application_date' => $applicationDate,
                'member_id' => $member->id,
                'benefit_type_id' => $benefitType->id,
                'requested_amount' => $requestedAmount,
                'approved_amount' => $status === 'Rejected' || $status === 'Draft' || $status === 'Submitted' || $status === 'Under Review' ? null : $requestedAmount,
                'reason' => "Application for {$benefitType->name} assistance.",
                'beneficiary_or_recipient' => $member->full_name,
                'requirements' => collect($benefitType->required_documents ?? [])
                    ->map(fn ($doc) => ['label' => $doc, 'completed' => true])
                    ->all(),
                'status' => $status,
                'eligibility' => $eligibility,
                'eligibility_result' => $eligibilityResult,
                'release_date' => $isReleased ? $applicationDate->copy()->addDays(5)->toDateString() : null,
                'rejection_reason' => $status === 'Rejected' ? 'Frequency limit for this benefit type has already been reached.' : null,
                'created_by' => 'Girlie B. Nacua',
            ]);
            $benefit->update(['application_number' => 'GCGEA-BEN-'.$applicationDate->year.'-'.str_pad((string) $benefit->id, 6, '0', STR_PAD_LEFT)]);
        }
    }
}
