<?php

use App\Models\BenefitType;
use App\Models\Contribution;
use App\Models\Deduction;
use App\Models\DeductionType;
use App\Models\Member;
use App\Models\Office;
use App\Services\BenefitProrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMember(): Member
{
    $office = Office::create(['code' => 'HQ', 'name' => 'Head Office', 'status' => 'Active']);

    return Member::create([
        'employee_number' => 'EMP-001',
        'surname' => 'Dela Cruz',
        'first_name' => 'Juan',
        'sex' => 'Male',
        'birthdate' => '1990-01-01',
        'civil_status' => 'Single',
        'permanent_address' => '123 Street',
        'cellphone_number' => '09171234567',
        'office_id' => $office->id,
        'position' => 'Clerk',
        'date_of_regular_appointment' => '2015-01-01',
        'employment_status' => 'Permanent',
        'membership_type' => 'Regular',
        'membership_date' => '2015-01-01',
        'membership_status' => 'Active',
        'retiree_status' => 'Not Retired',
        'is_draft' => false,
    ]);
}

/** Posts $months distinct monthly-dues contribution periods for the member, starting Jan of $startYear. */
function postDuesMonths(Member $member, int $months, int $startYear = 2018): void
{
    for ($i = 0; $i < $months; $i++) {
        $period = sprintf('%04d-%02d', $startYear + intdiv($i, 12), ($i % 12) + 1);
        Contribution::create([
            'reference_number' => 'CN-'.$member->id.'-'.$i,
            'member_id' => $member->id,
            'contribution_period' => $period,
            'amount' => 100,
            'payment_date' => "{$period}-15",
            'payment_method' => 'Payroll Deduction',
            'encoded_by' => 'Test',
            'status' => 'Posted',
        ]);
    }
}

/** Posts $months distinct Pabaon deduction periods for the member. */
function postPabaonMonths(Member $member, int $months, int $startYear = 2018): DeductionType
{
    $type = DeductionType::create(['name' => 'Cash Pabaon', 'code' => 'pabaon', 'is_active' => true, 'sort_order' => 1]);

    for ($i = 0; $i < $months; $i++) {
        $period = sprintf('%04d-%02d', $startYear + intdiv($i, 12), ($i % 12) + 1);
        Deduction::create([
            'reference_number' => 'DD-'.$member->id.'-'.$i,
            'member_id' => $member->id,
            'deduction_type_id' => $type->id,
            'period' => $period,
            'amount' => 200,
            'payment_date' => "{$period}-15",
            'encoded_by' => 'Test',
            'status' => 'Posted',
        ]);
    }

    return $type;
}

it('prorates a dues-based Core Benefit by contribution months (Resolution 24-2026 Table 1)', function () {
    $member = makeMember();
    postDuesMonths($member, 40); // falls in the 36-47 month tier -> 55%

    $benefitType = BenefitType::create([
        'name' => 'Retirement and Separation Benefit',
        'description' => 'Core benefit',
        'default_amount' => 10000,
        'maximum_amount' => 10000,
        'proration_basis' => 'dues',
        'required_membership_months' => 0,
        'frequency_limit' => 'Once',
        'approval_required' => true,
        'status' => 'Active',
    ]);
    foreach ([
        [12, 23, 35], [24, 35, 45], [36, 47, 55], [48, 59, 65],
        [60, 71, 75], [72, 83, 85], [84, 95, 95], [96, null, 100],
    ] as [$min, $max, $pct]) {
        $benefitType->prorationTiers()->create(['min_months' => $min, 'max_months' => $max, 'percentage' => $pct]);
    }

    $result = app(BenefitProrationService::class)->computeAmount($benefitType->fresh(['prorationTiers', 'fyAmounts']), $member);

    expect($result['monthsPaid'])->toBe(40)
        ->and($result['tier']->percentage)->toEqual('55.00')
        ->and($result['amount'])->toBe(5500.0);
});

it('prorates the FY-escalating Cash Pabaon Program by Pabaon-deduction months (Resolution 24-2026 Table 2)', function () {
    $member = makeMember();
    postPabaonMonths($member, 90); // falls in the 84-95 month tier -> 45%

    $benefitType = BenefitType::create([
        'name' => 'Cash Pabaon Program',
        'description' => 'FY-escalating prorated Pabaon claim',
        'default_amount' => 70000,
        'maximum_amount' => 70000,
        'proration_basis' => 'pabaon',
        'required_membership_months' => 0,
        'frequency_limit' => 'Once',
        'approval_required' => true,
        'status' => 'Active',
    ]);
    $benefitType->prorationTiers()->create(['min_months' => 84, 'max_months' => 95, 'percentage' => 45]);
    $benefitType->fyAmounts()->createMany([
        ['fiscal_year' => 2026, 'base_amount' => 70000],
        ['fiscal_year' => 2027, 'base_amount' => 80000],
        ['fiscal_year' => 2028, 'base_amount' => 90000],
        ['fiscal_year' => null, 'base_amount' => 100000],
    ]);

    $service = app(BenefitProrationService::class);
    $fresh = $benefitType->fresh(['prorationTiers', 'fyAmounts']);

    // FY2026: 45% of 70,000
    expect($service->computeAmount($fresh, $member, 2026)['amount'])->toBe(31500.0);
    // FY2027: 45% of 80,000 (matches the plan's own worked example)
    expect($service->computeAmount($fresh, $member, 2027)['amount'])->toBe(36000.0);
    // FY2031 (unconfigured, beyond the catch-all): falls to the null "and beyond" row, 45% of 100,000
    expect($service->computeAmount($fresh, $member, 2031)['amount'])->toBe(45000.0);
});

it('returns a null amount for a benefit type with no proration tiers (flat behavior unaffected)', function () {
    $member = makeMember();

    $benefitType = BenefitType::create([
        'name' => 'Flat Benefit',
        'description' => 'No proration',
        'default_amount' => 5000,
        'maximum_amount' => 5000,
        'required_membership_months' => 0,
        'frequency_limit' => 'Once',
        'approval_required' => true,
        'status' => 'Active',
    ]);

    $result = app(BenefitProrationService::class)->computeAmount($benefitType->fresh(['prorationTiers', 'fyAmounts']), $member);

    expect($result['amount'])->toBeNull();
});
