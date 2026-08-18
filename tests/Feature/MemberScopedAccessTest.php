<?php

use App\Http\Controllers\BenefitApplicationController;
use App\Http\Controllers\LoanController;
use App\Models\BenefitApplication;
use App\Models\BenefitType;
use App\Models\Loan;
use App\Models\LoanType;
use App\Models\Member;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A GCGEA Member self-service account (User::member_id set) must only ever
 * see and create records tied to its own Member — regardless of whatever
 * role/permissions the account carries. See LoanController/BenefitApplication
 * Controller's applyFilters()/guardMemberScope()/store().
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    $office = Office::create(['code' => 'HQ', 'name' => 'Head Office', 'status' => 'Active']);

    $this->memberA = Member::create([
        'employee_number' => 'EMP-001', 'surname' => 'Dela Cruz', 'first_name' => 'Juan', 'sex' => 'Male',
        'birthdate' => '1990-01-01', 'civil_status' => 'Single', 'permanent_address' => '123 Street',
        'cellphone_number' => '09171234567', 'office_id' => $office->id, 'position' => 'Clerk',
        'date_of_regular_appointment' => '2015-01-01', 'employment_status' => 'Permanent', 'membership_type' => 'Regular',
        'membership_date' => '2015-01-01', 'membership_status' => 'Active', 'retiree_status' => 'Not Retired',
        'is_draft' => false, 'member_number' => 'GCGEA-MEM-000001', 'created_by' => 'Tester',
    ]);

    $this->memberB = Member::create([
        'employee_number' => 'EMP-002', 'surname' => 'Santos', 'first_name' => 'Maria', 'sex' => 'Female',
        'birthdate' => '1992-01-01', 'civil_status' => 'Single', 'permanent_address' => '456 Street',
        'cellphone_number' => '09171234568', 'office_id' => $office->id, 'position' => 'Clerk',
        'date_of_regular_appointment' => '2016-01-01', 'employment_status' => 'Permanent', 'membership_type' => 'Regular',
        'membership_date' => '2016-01-01', 'membership_status' => 'Active', 'retiree_status' => 'Not Retired',
        'is_draft' => false, 'member_number' => 'GCGEA-MEM-000002', 'created_by' => 'Tester',
    ]);

    $this->memberAccount = User::factory()->create([
        'role_id' => Role::where('code', 'gcgea_member')->value('id'),
        'member_id' => $this->memberA->id,
    ]);

    $loanType = LoanType::create([
        'name' => 'Salary Loan', 'description' => 'Test', 'min_amount' => 1000, 'max_amount' => 100000,
        'default_interest_rate' => 1.5, 'interest_method' => 'Diminishing', 'processing_fee' => 100,
        'max_term_months' => 24, 'required_membership_months' => 0, 'required_contribution_months' => 0,
        'allow_existing_active_loan' => true, 'status' => 'Active',
    ]);

    $this->loanA = Loan::create([
        'application_number' => 'GCGEA-LN-A', 'application_date' => now(), 'member_id' => $this->memberA->id,
        'loan_type_id' => $loanType->id, 'requested_amount' => 10000, 'term_months' => 12, 'interest_rate' => 1.5,
        'processing_fee' => 100, 'purpose' => 'Test', 'payment_method' => 'Payroll Deduction',
        'first_due_date' => now()->addMonth(), 'principal' => 10000, 'total_interest' => 900, 'net_proceeds' => 9900,
        'total_amount_payable' => 10900, 'monthly_amortization' => 908.33, 'outstanding_balance' => 0,
        'status' => 'Submitted', 'created_by' => 'Tester',
    ]);
    $this->loanB = Loan::create([
        'application_number' => 'GCGEA-LN-B', 'application_date' => now(), 'member_id' => $this->memberB->id,
        'loan_type_id' => $loanType->id, 'requested_amount' => 10000, 'term_months' => 12, 'interest_rate' => 1.5,
        'processing_fee' => 100, 'purpose' => 'Test', 'payment_method' => 'Payroll Deduction',
        'first_due_date' => now()->addMonth(), 'principal' => 10000, 'total_interest' => 900, 'net_proceeds' => 9900,
        'total_amount_payable' => 10900, 'monthly_amortization' => 908.33, 'outstanding_balance' => 0,
        'status' => 'Submitted', 'created_by' => 'Tester',
    ]);

    $benefitType = BenefitType::create([
        'name' => 'Test Benefit', 'category' => 'Nuclear Family Mortuary', 'description' => 'Test',
        'default_amount' => 5000, 'status' => 'Active',
    ]);
    $this->benefitA = BenefitApplication::create([
        'application_number' => 'GCGEA-BN-A', 'application_date' => now(), 'member_id' => $this->memberA->id,
        'benefit_type_id' => $benefitType->id, 'requested_amount' => 5000, 'reason' => 'Test',
        'status' => 'Submitted', 'created_by' => 'Tester',
    ]);
    $this->benefitB = BenefitApplication::create([
        'application_number' => 'GCGEA-BN-B', 'application_date' => now(), 'member_id' => $this->memberB->id,
        'benefit_type_id' => $benefitType->id, 'requested_amount' => 5000, 'reason' => 'Test',
        'status' => 'Submitted', 'created_by' => 'Tester',
    ]);

    $this->request = Request::create('/api/loans');
    $this->request->setUserResolver(fn () => $this->memberAccount);
});

test('a member account only sees its own loans in the list', function () {
    $response = app(LoanController::class)->index($this->request);
    $data = json_decode($response->getContent(), true)['data'];

    expect($data)->toHaveCount(1);
    expect($data[0]['memberId'])->toBe((string) $this->memberA->id);
});

test('a member account can view its own loan by id', function () {
    $resource = app(LoanController::class)->show($this->request, $this->loanA);

    expect($resource->resolve()['memberId'])->toBe((string) $this->memberA->id);
});

test('a member account is blocked from viewing another member\'s loan by id', function () {
    expect(fn () => app(LoanController::class)->show($this->request, $this->loanB))
        ->toThrow(HttpException::class);
});

test('a member account only sees its own benefit applications in the list', function () {
    $response = app(BenefitApplicationController::class)->index($this->request);
    $data = json_decode($response->getContent(), true)['data'];

    expect($data)->toHaveCount(1);
    expect($data[0]['memberId'])->toBe((string) $this->memberA->id);
});

test('a member account is blocked from viewing another member\'s benefit application by id', function () {
    expect(fn () => app(BenefitApplicationController::class)->show($this->request, $this->benefitB))
        ->toThrow(HttpException::class);
});
