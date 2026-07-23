<?php

namespace Database\Seeders;

use App\Models\BenefitApplication;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Role;
use App\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the 3 Phase 1 pilot workflows. Stage approvers are always resolved by
 * role *code* lookup, never a hardcoded role id — the whole point of this
 * engine is that these assignments are then freely reconfigurable from the
 * Administration > Approval Workflow admin page without touching this file.
 */
class WorkflowDefinitionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $membershipOfficer = Role::where('code', 'membership_officer')->value('id');
        $loanOfficer = Role::where('code', 'loan_officer')->value('id');
        $benefitsOfficer = Role::where('code', 'benefits_officer')->value('id');
        $approvingOfficer = Role::where('code', 'approving_officer')->value('id');
        $treasurer = Role::where('code', 'treasurer')->value('id');

        $memberRegistration = WorkflowDefinition::updateOrCreate(
            ['module_key' => 'member_registration'],
            ['label' => 'Member Registration', 'subject_model' => Member::class, 'is_enabled' => true]
        );
        $memberRegistration->stages()->updateOrCreate(['sequence' => 1], [
            'code' => 'approve',
            'label' => 'Membership Officer Approval',
            'stage_type' => 'approve',
            'approver_type' => 'role',
            'approver_role_id' => $membershipOfficer,
            'approver_user_id' => null,
            'approver_office_id' => null,
            'required_permission_code' => 'members.approve',
            'is_final' => true,
        ]);

        $loanApplication = WorkflowDefinition::updateOrCreate(
            ['module_key' => 'loan_application'],
            ['label' => 'Loan Application', 'subject_model' => Loan::class, 'is_enabled' => true]
        );
        $loanApplication->stages()->updateOrCreate(['sequence' => 1], [
            'code' => 'review',
            'label' => 'Loan Officer Review',
            'stage_type' => 'review',
            'approver_type' => 'role',
            'approver_role_id' => $loanOfficer,
            'approver_user_id' => null,
            'approver_office_id' => null,
            'required_permission_code' => 'loans.review',
            'is_final' => false,
        ]);
        $loanApplication->stages()->updateOrCreate(['sequence' => 2], [
            'code' => 'approve',
            'label' => 'Approving Officer / President Approval',
            'stage_type' => 'approve',
            'approver_type' => 'role',
            'approver_role_id' => $approvingOfficer,
            'approver_user_id' => null,
            'approver_office_id' => null,
            'required_permission_code' => 'loans.approve',
            'is_final' => false,
        ]);
        $loanApplication->stages()->updateOrCreate(['sequence' => 3], [
            'code' => 'release',
            'label' => 'Treasurer Release',
            'stage_type' => 'release',
            'approver_type' => 'role',
            'approver_role_id' => $treasurer,
            'approver_user_id' => null,
            'approver_office_id' => null,
            'required_permission_code' => 'loans.release',
            'is_final' => true,
        ]);

        $benefitApplication = WorkflowDefinition::updateOrCreate(
            ['module_key' => 'benefit_application'],
            ['label' => 'Benefit Application', 'subject_model' => BenefitApplication::class, 'is_enabled' => true]
        );
        $benefitApplication->stages()->updateOrCreate(['sequence' => 1], [
            'code' => 'review',
            'label' => 'Benefits Officer Review',
            'stage_type' => 'review',
            'approver_type' => 'role',
            'approver_role_id' => $benefitsOfficer,
            'approver_user_id' => null,
            'approver_office_id' => null,
            'required_permission_code' => 'benefits.review',
            'is_final' => false,
        ]);
        $benefitApplication->stages()->updateOrCreate(['sequence' => 2], [
            'code' => 'approve',
            'label' => 'Approving Officer / President Approval',
            'stage_type' => 'approve',
            'approver_type' => 'role',
            'approver_role_id' => $approvingOfficer,
            'approver_user_id' => null,
            'approver_office_id' => null,
            'required_permission_code' => 'benefits.approve',
            'is_final' => false,
        ]);
        $benefitApplication->stages()->updateOrCreate(['sequence' => 3], [
            'code' => 'release',
            'label' => 'Treasurer Release',
            'stage_type' => 'release',
            'approver_type' => 'role',
            'approver_role_id' => $treasurer,
            'approver_user_id' => null,
            'approver_office_id' => null,
            'required_permission_code' => 'benefits.release',
            'is_final' => true,
        ]);
    }
}
