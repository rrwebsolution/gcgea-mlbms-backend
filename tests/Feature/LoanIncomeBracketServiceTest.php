<?php

use App\Models\LoanType;
use App\Services\LoanIncomeBracketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSolidarityLoanType(): LoanType
{
    $loanType = LoanType::create([
        'name' => 'Solidarity Cash Assistance Loan',
        'description' => 'Income-bracketed loan (Resolution 24-2026 Table 3)',
        'min_amount' => 20000,
        'max_amount' => 50000,
        'default_interest_rate' => 1,
        'interest_method' => 'Flat Interest',
        'processing_fee' => 0,
        'service_charge_percent' => 1,
        'max_term_months' => 36,
        'required_membership_months' => 0,
        'required_contribution_months' => 0,
        'allow_existing_active_loan' => false,
        'status' => 'Active',
    ]);

    $loanType->incomeBrackets()->createMany([
        ['min_net_pay' => 5000, 'max_net_pay' => 7000, 'loanable_amount' => 20000],
        ['min_net_pay' => 8000, 'max_net_pay' => 10000, 'loanable_amount' => 30000],
        ['min_net_pay' => 11000, 'max_net_pay' => 12000, 'loanable_amount' => 40000],
        ['min_net_pay' => 13000, 'max_net_pay' => null, 'loanable_amount' => 50000],
    ]);

    return $loanType->fresh('incomeBrackets');
}

it('resolves the correct loanable amount per net-pay bracket (Resolution 24-2026 Table 3)', function () {
    $loanType = makeSolidarityLoanType();
    $service = app(LoanIncomeBracketService::class);

    expect($service->bracketFor($loanType, 6000)->loanable_amount)->toEqual('20000.00')
        ->and($service->bracketFor($loanType, 9000)->loanable_amount)->toEqual('30000.00')
        ->and($service->bracketFor($loanType, 11500)->loanable_amount)->toEqual('40000.00')
        ->and($service->bracketFor($loanType, 25000)->loanable_amount)->toEqual('50000.00'); // open-ended top bracket
});

it('returns no bracket for net pay below the lowest configured tier', function () {
    $loanType = makeSolidarityLoanType();

    expect(app(LoanIncomeBracketService::class)->bracketFor($loanType, 3000))->toBeNull();
});

it('returns no bracket for a gap between configured tiers', function () {
    $loanType = makeSolidarityLoanType();

    // 7,500 falls in the gap between the 5-7k and 8-10k brackets — Table 3 has
    // no coverage there, so no bracket should match rather than silently
    // picking the nearest one.
    expect(app(LoanIncomeBracketService::class)->bracketFor($loanType, 7500))->toBeNull();
});
