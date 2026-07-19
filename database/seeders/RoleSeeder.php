<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Mirrors src/services/mock-data/roles.ts + the permissionsForRole() presets in
 * src/constants/permissions.ts — keep the two in sync when roles change.
 */
class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $allCodes = Permission::pluck('code')->all();
        $viewOnly = Permission::query()->where('code', 'like', '%.view')->pluck('code')->all();

        $membershipOfficer = [
            'dashboard.view',
            'members.view', 'members.create', 'members.update', 'members.archive', 'members.restore',
            'members.import', 'members.export', 'members.print',
            'beneficiaries.view', 'beneficiaries.create', 'beneficiaries.update', 'beneficiaries.delete',
            'offices.view',
            'reports.view', 'reports.export', 'reports.print', 'reports.member',
            'drafts.view_own', 'drafts.create', 'drafts.update_own', 'drafts.delete_own', 'drafts.submit',
        ];

        $loanOfficer = [
            'dashboard.view',
            'members.view',
            'offices.view',
            'loans.view', 'loans.create', 'loans.update', 'loans.submit', 'loans.print', 'loans.export',
            'loan_payments.view', 'loan_payments.create', 'loan_payments.print_receipt',
            'reports.view', 'reports.export', 'reports.print', 'reports.loan',
            'drafts.view_own', 'drafts.create', 'drafts.update_own', 'drafts.delete_own', 'drafts.submit',
        ];

        $treasurer = [
            'dashboard.view',
            'members.view',
            'offices.view',
            'contributions.view', 'contributions.create', 'contributions.update', 'contributions.void',
            'contributions.bulk_create', 'contributions.import', 'contributions.replace_duplicate',
            'contributions.export', 'contributions.print',
            'loan_payments.view', 'loan_payments.create', 'loan_payments.update', 'loan_payments.void',
            'loan_payments.import', 'loan_payments.print_receipt', 'loan_payments.export',
            'reports.view', 'reports.export', 'reports.print', 'reports.financial', 'reports.contribution',
        ];

        $benefitsOfficer = [
            'dashboard.view',
            'members.view',
            'offices.view',
            'benefits.view', 'benefits.create', 'benefits.update', 'benefits.submit', 'benefits.print', 'benefits.export',
            'reports.view', 'reports.export', 'reports.print', 'reports.benefit',
            'drafts.view_own', 'drafts.create', 'drafts.update_own', 'drafts.delete_own', 'drafts.submit',
        ];

        $approvingOfficer = [
            'dashboard.view',
            'members.view',
            'offices.view',
            'loans.view', 'loans.review', 'loans.recommend', 'loans.approve', 'loans.reject', 'loans.release',
            'loans.restructure', 'loans.print', 'loans.export',
            'benefits.view', 'benefits.review', 'benefits.approve', 'benefits.reject', 'benefits.release',
            'benefits.print', 'benefits.export',
            'reports.view', 'reports.export', 'reports.print', 'reports.loan', 'reports.benefit',
        ];

        $auditorViewer = array_values(array_unique([...$viewOnly, 'audit_logs.view', 'audit_logs.export', 'drafts.view_all']));

        $roles = [
            ['id' => 1, 'name' => 'Super Administrator', 'code' => 'super_administrator', 'description' => 'Full, unrestricted access to all modules and settings.', 'is_system_role' => true, 'permissions' => $allCodes],
            ['id' => 2, 'name' => 'Membership Officer', 'code' => 'membership_officer', 'description' => 'Manages member registration, profiles, and beneficiaries.', 'is_system_role' => true, 'permissions' => $membershipOfficer],
            ['id' => 3, 'name' => 'Loan Officer', 'code' => 'loan_officer', 'description' => 'Encodes and processes loan applications and releases.', 'is_system_role' => true, 'permissions' => $loanOfficer],
            ['id' => 4, 'name' => 'Treasurer', 'code' => 'treasurer', 'description' => 'Handles contributions, collections, and payment posting.', 'is_system_role' => true, 'permissions' => $treasurer],
            ['id' => 5, 'name' => 'Benefits Officer', 'code' => 'benefits_officer', 'description' => 'Processes benefit applications and releases.', 'is_system_role' => true, 'permissions' => $benefitsOfficer],
            ['id' => 6, 'name' => 'Approving Officer', 'code' => 'approving_officer', 'description' => 'Reviews and approves loans and benefit applications.', 'is_system_role' => true, 'permissions' => $approvingOfficer],
            ['id' => 7, 'name' => 'Auditor / Viewer', 'code' => 'auditor_viewer', 'description' => 'Read-only access for audit and oversight purposes.', 'is_system_role' => true, 'permissions' => $auditorViewer],
            ['id' => 8, 'name' => 'Senior Loan Officer', 'code' => 'senior_loan_officer', 'description' => 'Loan Officer duties plus the ability to recommend applications for approval.', 'is_system_role' => false, 'permissions' => array_values(array_unique([...$loanOfficer, 'loans.review', 'loans.recommend']))],
            ['id' => 9, 'name' => 'Regional Auditor', 'code' => 'regional_auditor', 'description' => 'Extended read-only access including sensitive audit log changes, for external/regional audit engagements.', 'is_system_role' => false, 'permissions' => array_values(array_unique([...$auditorViewer, 'audit_logs.view_sensitive_changes', 'reports.audit']))],
            ['id' => 10, 'name' => 'Branch Coordinator', 'code' => 'branch_coordinator', 'description' => 'Front-desk coordinator role for satellite offices — light member and reporting access without financial actions.', 'is_system_role' => false, 'permissions' => [
                'dashboard.view',
                'members.view', 'members.create', 'members.update', 'members.print',
                'beneficiaries.view', 'beneficiaries.create',
                'offices.view',
                'reports.view', 'reports.member',
                'drafts.view_own', 'drafts.create', 'drafts.update_own', 'drafts.delete_own', 'drafts.submit',
            ]],
        ];

        foreach ($roles as $definition) {
            $role = Role::updateOrCreate(
                ['id' => $definition['id']],
                [
                    'name' => $definition['name'],
                    'code' => $definition['code'],
                    'description' => $definition['description'],
                    'is_system_role' => $definition['is_system_role'],
                    'status' => 'Active',
                ]
            );

            $role->permissions()->sync($definition['permissions']);
        }
    }
}
