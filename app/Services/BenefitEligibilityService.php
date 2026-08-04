<?php

namespace App\Services;

use App\Models\BenefitApplication;
use App\Models\BenefitType;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SystemSetting;
use Carbon\Carbon;

/**
 * Ports evaluateBenefitEligibility() from src/utils/eligibility.ts 1:1 —
 * including the fragile frequency-limit string-matching (checks #3), which is
 * intentional: BenefitType.frequency_limit is deliberately a free-text field,
 * not a structured one. Do not "fix" this here.
 */
class BenefitEligibilityService
{
    /** GCGEA Board Resolution No. 24-2026, Table 2 — see cashPabaonChecks(). */
    private const CASH_PABAON_PROGRAM_NAME = 'Cash Pabaon Program';

    /**
     * @return array<int, array{label: string, passed: bool, detail: string}>
     */
    public function evaluate(Member $member, BenefitType $benefitType, float $requestedAmount, ?string $beneficiaryOrRecipient = null): array
    {
        $checks = [];
        $settings = SystemSetting::benefit();
        $isCashPabaonDeathClaim = $benefitType->name === self::CASH_PABAON_PROGRAM_NAME && $member->membership_status === 'Deceased';

        $checks[] = [
            'label' => 'Active Member',
            'passed' => $member->membership_status === 'Active' || $isCashPabaonDeathClaim,
            'detail' => $member->membership_status === 'Active'
                ? 'Member is in active standing.'
                : ($isCashPabaonDeathClaim
                    ? 'Member is deceased; claim filed on behalf of a qualified nuclear family member under the Cash Pabaon Program.'
                    : "Member status is {$member->membership_status}, not Active."),
        ];

        $membershipMonths = $this->monthsBetween(Carbon::parse($member->membership_date), now());
        $checks[] = [
            'label' => 'Membership Duration',
            'passed' => $membershipMonths >= $benefitType->required_membership_months,
            'detail' => "Member for {$membershipMonths} month(s); requires {$benefitType->required_membership_months}.",
        ];

        $priorBenefitCount = BenefitApplication::where('member_id', $member->id)
            ->where('benefit_type_id', $benefitType->id)
            ->whereIn('status', ['Released', 'Completed'])
            ->whereDate('release_date', '>=', $this->benefitYearStartedAt($settings['benefitYearResetMonth']))
            ->count();
        $mentionsYear = str_contains(strtolower($benefitType->frequency_limit), 'year');
        $frequencyPassed = $priorBenefitCount === 0 || ($mentionsYear ? $priorBenefitCount < 2 : $priorBenefitCount === 0);
        $checks[] = [
            'label' => 'Frequency Limit',
            'passed' => $frequencyPassed,
            'detail' => "{$priorBenefitCount} prior release(s) of this benefit type; limit \"{$benefitType->frequency_limit}\".",
        ];

        $hasPendingApplication = BenefitApplication::where('member_id', $member->id)
            ->where('benefit_type_id', $benefitType->id)
            ->whereIn('status', ['Draft', 'Submitted', 'Under Review', 'For Approval'])
            ->exists();
        $checks[] = [
            'label' => 'No Duplicate Pending Application',
            'passed' => $settings['allowMultiplePendingApplications'] || ! $hasPendingApplication,
            'detail' => $settings['allowMultiplePendingApplications']
                ? 'Multiple pending applications are allowed by Benefit Settings.'
                : ($hasPendingApplication
                ? 'Member already has a pending application for this benefit type.'
                : 'No pending application of this type.'),
        ];

        $profileComplete = $member->isProfileComplete();
        $checks[] = [
            'label' => 'Profile Complete',
            'passed' => $profileComplete,
            'detail' => $profileComplete ? 'Member profile is complete.' : 'Member profile is missing required information.',
        ];

        $checks[] = [
            'label' => 'Requested Amount Within Maximum',
            'passed' => $requestedAmount <= $benefitType->maximum_amount,
            'detail' => "Requested ₱{$requestedAmount}; maximum ₱{$benefitType->maximum_amount}.",
        ];

        if ($benefitType->name === self::CASH_PABAON_PROGRAM_NAME) {
            $checks = array_merge($checks, $this->cashPabaonChecks($member, $beneficiaryOrRecipient));
        }

        if (($settings['requireRetiredStatusForRetirementBenefit'] ?? true)
            && $benefitType->name === 'Retirement and Separation Benefit') {
            $isRetired = $member->retiree_status === 'Retired';
            $checks[] = [
                'label' => 'Retired Status Required',
                'passed' => $isRetired,
                'detail' => $isRetired
                    ? 'Member Retiree Status is Retired.'
                    : "Member Retiree Status is {$member->retiree_status}; this benefit requires Retired status.",
            ];
        }

        return $checks;
    }

    /**
     * Cash Pabaon Program-specific checks layered on top of the generic
     * checks above — retirement age (60-65), separation age (59 and below;
     * a promotion-based separation can't be verified from member data, so an
     * over-59 separation is a warning, not a hard block), the qualified
     * nuclear family beneficiary rule for a deceased member's claim, and
     * outstanding obligations. Mirrors evaluateCashPabaonClaimChecks() in
     * src/utils/eligibility.ts 1:1.
     *
     * @return array<int, array{label: string, passed: bool, detail: string}>
     */
    private function cashPabaonChecks(Member $member, ?string $beneficiaryOrRecipient): array
    {
        $checks = [];

        if ($member->membership_status === 'Deceased') {
            $recipients = $this->resolveRecipients($member, $beneficiaryOrRecipient);
            $pattern = $this->qualifiedNuclearFamilyPattern($member->civil_status);
            $passed = $recipients['type'] === 'Beneficiary'
                && $recipients['relationships'] !== []
                && array_reduce($recipients['relationships'], fn ($carry, $r) => $carry && preg_match($pattern, $r) === 1, true);

            $checks[] = [
                'label' => 'Qualified Nuclear Family Beneficiary',
                'passed' => $passed,
                'detail' => $passed
                    ? 'Recipient relationship(s) ('.implode(', ', $recipients['relationships']).") qualify as nuclear family for this deceased member's Cash Pabaon claim."
                    : 'Cash Pabaon death claims must be filed for a registered beneficiary who is a qualified nuclear family member (spouse or child if married; parent or sibling if unmarried).',
            ];
        } else {
            $age = $this->calculateAge($member->birthdate);
            if ($member->retiree_status === 'Retired') {
                $passed = $age >= 60 && $age <= 65;
                $checks[] = [
                    'label' => 'Retirement Age Within Policy',
                    'passed' => $passed,
                    'detail' => $passed
                        ? "Member is {$age} year(s) old, within the 60-65 retirement bracket."
                        : "Member is {$age} year(s) old; Cash Pabaon retirement claims require an age between 60 and 65.",
                ];
            } else {
                $passed = $age <= 59;
                $checks[] = [
                    'label' => 'Separation Age Within Policy',
                    'passed' => $passed,
                    'detail' => $passed
                        ? "Member is {$age} year(s) old, within the 59-and-below separation bracket."
                        : "Member is {$age} year(s) old, above the 59-and-below separation bracket — only qualifies if the separation is due to promotion (verify manually).",
                ];
            }
        }

        $hasOutstandingObligations = Loan::query()
            ->where('member_id', $member->id)
            ->where('status', 'Overdue')
            ->exists();

        $checks[] = [
            'label' => 'No Outstanding Obligations',
            'passed' => ! $hasOutstandingObligations,
            'detail' => $hasOutstandingObligations
                ? 'Member has outstanding/overdue obligations with GCGEA that must be settled before this Cash Pabaon claim can proceed.'
                : 'No outstanding overdue obligations on record.',
        ];

        return $checks;
    }

    /**
     * Parses the free-text beneficiary_or_recipient column (e.g. "Juan Dela
     * Cruz (Sibling)") back into recipient names and, where each name
     * matches one of the member's registered Beneficiary records, its
     * relationship — mirrors the frontend's identical draft-restore parsing
     * in CreateBenefitApplicationPage.tsx.
     *
     * @return array{type: string, names: array<int, string>, relationships: array<int, string>}
     */
    private function resolveRecipients(Member $member, ?string $beneficiaryOrRecipient): array
    {
        $names = array_values(array_filter(array_map(
            fn ($name) => trim(preg_replace('/\s*\([^)]*\)\s*$/', '', trim($name))),
            explode(',', (string) $beneficiaryOrRecipient)
        )));

        if ($names === []) {
            return ['type' => 'Member', 'names' => [], 'relationships' => []];
        }

        $beneficiariesByName = $member->beneficiaries->keyBy('full_name');
        $relationships = array_values(array_filter(array_map(
            fn ($name) => $beneficiariesByName->get($name)?->relationship,
            $names
        )));

        $type = count($relationships) === count($names) ? 'Beneficiary' : 'Member';

        return ['type' => $type, 'names' => $names, 'relationships' => $relationships];
    }

    private function qualifiedNuclearFamilyPattern(string $civilStatus): string
    {
        return $civilStatus === 'Married' ? '/spouse|wife|husband|child|son|daughter/i' : '/parent|father|mother|sibling|brother|sister/i';
    }

    private function calculateAge(?string $birthdate): int
    {
        return $birthdate ? Carbon::parse($birthdate)->age : 0;
    }

    /**
     * @param  array<int, array{label: string, passed: bool, detail: string}>  $checks
     */
    public function resultFor(array $checks): string
    {
        $criticalLabels = [
            'Active Member',
            'No Duplicate Pending Application',
            'Retirement Age Within Policy',
            'Qualified Nuclear Family Beneficiary',
            'No Outstanding Obligations',
        ];
        $failed = array_filter($checks, fn ($c) => ! $c['passed']);

        if (array_intersect(array_column($failed, 'label'), $criticalLabels) !== []) {
            return 'Not Eligible';
        }

        return $failed !== [] ? 'Eligible with Warning' : 'Eligible';
    }

    private function monthsBetween(Carbon $start, Carbon $end): int
    {
        return ($end->year - $start->year) * 12 + ($end->month - $start->month);
    }

    private function benefitYearStartedAt(string $month): Carbon
    {
        $monthNumber = Carbon::parse("1 {$month}")->month;
        $start = now()->startOfYear()->month($monthNumber)->startOfMonth();

        return $start->isFuture() ? $start->subYear() : $start;
    }
}
