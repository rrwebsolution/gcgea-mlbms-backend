<?php

use App\Exceptions\ApprovalActionConflictException;
use App\Models\ApprovalInstance;
use App\Models\Loan;
use App\Models\LoanType;
use App\Models\Member;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalWorkflowService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkflowDefinitionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, WorkflowDefinitionSeeder::class]);
    $this->workflow = app(ApprovalWorkflowService::class);

    $office = Office::create(['code' => 'HQ', 'name' => 'Head Office', 'status' => 'Active']);

    $this->loanOfficer = User::factory()->create(['role_id' => Role::where('code', 'loan_officer')->value('id')]);
    $this->approvingOfficer = User::factory()->create(['role_id' => Role::where('code', 'approving_officer')->value('id')]);
    $this->treasurer = User::factory()->create(['role_id' => Role::where('code', 'treasurer')->value('id')]);
    $this->membershipOfficer = User::factory()->create(['role_id' => Role::where('code', 'membership_officer')->value('id')]);
    $this->outsider = User::factory()->create(['role_id' => Role::where('code', 'benefits_officer')->value('id')]);

    $this->member = Member::create([
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
        'member_number' => 'GCGEA-MEM-000001',
        'created_by' => 'Tester',
    ]);

    $loanType = LoanType::create([
        'name' => 'Salary Loan',
        'description' => 'Test',
        'min_amount' => 1000,
        'max_amount' => 100000,
        'default_interest_rate' => 1.5,
        'interest_method' => 'Diminishing',
        'processing_fee' => 100,
        'max_term_months' => 24,
        'required_membership_months' => 0,
        'required_contribution_months' => 0,
        'allow_existing_active_loan' => true,
        'status' => 'Active',
    ]);

    $this->loan = Loan::create([
        'application_number' => 'GCGEA-LN-2026-000001',
        'application_date' => now(),
        'member_id' => $this->member->id,
        'loan_type_id' => $loanType->id,
        'requested_amount' => 10000,
        'term_months' => 12,
        'interest_rate' => 1.5,
        'processing_fee' => 100,
        'purpose' => 'Test purpose',
        'payment_method' => 'Payroll Deduction',
        'first_due_date' => now()->addMonth(),
        'principal' => 10000,
        'total_interest' => 900,
        'net_proceeds' => 9900,
        'total_amount_payable' => 10900,
        'monthly_amortization' => 908.33,
        'outstanding_balance' => 0,
        'status' => 'Submitted',
        'created_by' => 'Tester',
    ]);
});

test('loan happy path progresses through review, treasury review, approve, release', function () {
    $this->workflow->startInstance($this->loan, 'loan_application', $this->loanOfficer);
    expect($this->loan->fresh()->status)->toBe('Under Review');

    $this->workflow->act($this->loan, $this->loanOfficer, 'review');
    expect($this->loan->fresh()->status)->toBe('Under Review');

    // Treasurer doesn't get loans.review by default — an admin has to opt this
    // org into the Treasury Review stage via Roles & Permissions.
    Role::where('code', 'treasurer')->first()->permissions()->attach('loans.review');

    $this->workflow->act($this->loan, $this->treasurer, 'review');
    expect($this->loan->fresh()->status)->toBe('For Approval');

    $this->workflow->act($this->loan, $this->approvingOfficer, 'approve');
    expect($this->loan->fresh()->status)->toBe('Approved');

    $instance = $this->workflow->act($this->loan, $this->treasurer, 'release', [
        'release_date' => now(),
        'release_reference_number' => 'REL-001',
        'release_method' => 'Cash',
        'actual_released_amount' => 9900,
    ]);

    expect($this->loan->fresh()->status)->toBe('Released');
    expect($instance->status)->toBe('released');
    expect($this->workflow->historyFor($this->loan->fresh()))->toHaveCount(5);
});

test('rejecting at the review stage marks the loan rejected and stops the workflow', function () {
    $this->workflow->startInstance($this->loan, 'loan_application', $this->loanOfficer);

    $this->workflow->act($this->loan, $this->loanOfficer, 'reject', remarks: 'Incomplete documents');

    expect($this->loan->fresh()->status)->toBe('Rejected');
    expect($this->loan->fresh()->rejection_reason)->toBe('Incomplete documents');
});

test('a user without the matching stage assignment cannot act', function () {
    $this->workflow->startInstance($this->loan, 'loan_application', $this->loanOfficer);

    expect(fn () => $this->workflow->act($this->loan, $this->outsider, 'review'))
        ->toThrow(AuthorizationException::class);
});

test('acting twice on an already-resolved instance throws a conflict instead of double-applying', function () {
    $this->workflow->startInstance($this->loan, 'loan_application', $this->loanOfficer);
    $this->workflow->act($this->loan, $this->loanOfficer, 'reject', remarks: 'no');

    expect(fn () => $this->workflow->act($this->loan->fresh(), $this->loanOfficer, 'reject', remarks: 'no'))
        ->toThrow(ApprovalActionConflictException::class);
});

test('member registration is a single stage approved directly by the membership officer', function () {
    $this->workflow->startInstance($this->member, 'member_registration', $this->membershipOfficer);

    $this->workflow->act($this->member, $this->membershipOfficer, 'approve');

    $instance = ApprovalInstance::where('subject_type', Member::class)->where('subject_id', $this->member->id)->first();
    expect($instance->status)->toBe('approved');
});

test('reassigning a stage approver mid-flight takes effect on the next action, no code changes needed', function () {
    $this->workflow->startInstance($this->loan, 'loan_application', $this->loanOfficer);

    // Stage 1 is currently assigned to the loan_officer role — an unrelated
    // role is (correctly) locked out.
    expect(fn () => $this->workflow->act($this->loan, $this->outsider, 'review'))
        ->toThrow(AuthorizationException::class);

    // The admin reassigns stage 1 away from "any loan_officer" to one
    // specific user — purely a config change, no deploy.
    $stage = WorkflowStage::where('workflow_definition_id', $this->loan->approvalInstance->workflow_definition_id)
        ->where('sequence', 1)
        ->firstOrFail();
    $stage->update([
        'approver_type' => 'user',
        'approver_role_id' => null,
        'approver_user_id' => $this->approvingOfficer->id,
    ]);

    // The previously-eligible loan officer is now locked out...
    expect(fn () => $this->workflow->act($this->loan, $this->loanOfficer, 'review'))
        ->toThrow(AuthorizationException::class);

    // ...and the newly assigned user can act instead.
    $this->workflow->act($this->loan, $this->approvingOfficer, 'review');
    expect($this->loan->fresh()->status)->toBe('For Approval');
});
