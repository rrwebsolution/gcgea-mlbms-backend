<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the permissionsForRole() presets in src/constants/permissions.ts —
 * keep the two in sync when roles change.
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
            'members.export', 'members.print',
            'members.review', 'members.approve', 'members.reject', 'members.auto_approve',
            'beneficiaries.view', 'beneficiaries.create', 'beneficiaries.update', 'beneficiaries.delete',
            'offices.view',
            'reports.view', 'reports.export', 'reports.print', 'reports.member',
            'drafts.view_own', 'drafts.create', 'drafts.update_own', 'drafts.delete_own', 'drafts.submit',
            'member_import.view', 'member_import.create', 'member_import.resolve_duplicates', 'member_import.manage_offices',
        ];

        $loanOfficer = [
            'dashboard.view',
            'members.view',
            'offices.view',
            'contributions.view', 'deductions.view',
            'loans.view', 'loans.create', 'loans.update', 'loans.submit', 'loans.review', 'loans.reloan', 'loans.print', 'loans.export',
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
            'loans.view', 'loans.release', 'loans.print',
            'benefits.view', 'benefits.release', 'benefits.print',
            'reports.view', 'reports.export', 'reports.print', 'reports.financial', 'reports.contribution',
            'payroll.manual.view', 'payroll.manual.create', 'payroll.manual.edit', 'payroll.manual.post',
            'payroll.bulk.view', 'payroll.bulk.create', 'payroll.bulk.edit', 'payroll.bulk.post',
            'payroll.import.view', 'payroll.import.rollback', 'payroll.history.view',
            'deduction_types.view', 'deductions.view', 'deductions.void',
            'annual_budgets.view', 'annual_budgets.manage', 'annual_budgets.submit',
            'disbursements.view', 'disbursements.manage', 'disbursements.submit', 'disbursements.pay', 'disbursements.print', 'disbursements.void',
        ];

        $benefitsOfficer = [
            'dashboard.view',
            'members.view',
            'offices.view',
            'contributions.view', 'deductions.view',
            'benefits.view', 'benefits.create', 'benefits.update', 'benefits.submit', 'benefits.review', 'benefits.print', 'benefits.export',
            'reports.view', 'reports.export', 'reports.print', 'reports.benefit',
            'drafts.view_own', 'drafts.create', 'drafts.update_own', 'drafts.delete_own', 'drafts.submit',
        ];

        $approvingOfficer = [
            'dashboard.view',
            'members.view',
            'offices.view',
            'loans.view', 'loans.review', 'loans.recommend', 'loans.approve', 'loans.reject',
            'loans.restructure', 'loans.print', 'loans.export',
            'benefits.view', 'benefits.review', 'benefits.approve', 'benefits.reject',
            'benefits.print', 'benefits.export',
            'reports.view', 'reports.export', 'reports.print', 'reports.loan', 'reports.benefit',
            'annual_budgets.view', 'annual_budgets.approve',
            'disbursements.view', 'disbursements.approve',
        ];

        $auditorViewer = array_values(array_unique([...$viewOnly, 'audit_logs.view', 'audit_logs.export', 'drafts.view_all', 'annual_budgets.view', 'disbursements.view']));

        $gcgeaMember = [
            'dashboard.view',
            'loans.view', 'loans.create', 'loans.submit',
            'benefits.view', 'benefits.create', 'benefits.submit',
        ];

        $roles = [
            ['id' => 1, 'name' => 'Super Administrator', 'code' => 'super_administrator', 'description' => 'Full, unrestricted access to all modules and settings.', 'is_system_role' => true, 'permissions' => $allCodes],
            ['id' => 2, 'name' => 'Membership Officer', 'code' => 'membership_officer', 'description' => 'Manages member registration, profiles, and beneficiaries.', 'is_system_role' => true, 'permissions' => $membershipOfficer],
            ['id' => 3, 'name' => 'Loan Officer', 'code' => 'loan_officer', 'description' => 'Encodes and processes loan applications and releases.', 'is_system_role' => true, 'permissions' => $loanOfficer],
            ['id' => 4, 'name' => 'Treasurer', 'code' => 'treasurer', 'description' => 'Prepares annual budgets and disbursements, manages collections, and records approved payments.', 'is_system_role' => true, 'permissions' => $treasurer],
            ['id' => 5, 'name' => 'Benefits Officer', 'code' => 'benefits_officer', 'description' => 'Processes benefit applications and releases.', 'is_system_role' => true, 'permissions' => $benefitsOfficer],
            ['id' => 6, 'name' => 'Approving Officer', 'code' => 'approving_officer', 'description' => 'Reviews and decides loan, benefit, annual budget, and disbursement submissions.', 'is_system_role' => true, 'permissions' => $approvingOfficer],
            ['id' => 7, 'name' => 'Auditor / Viewer', 'code' => 'auditor_viewer', 'description' => 'Read-only access to operational and financial records for audit and oversight.', 'is_system_role' => true, 'permissions' => $auditorViewer],
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
            ['id' => 11, 'name' => 'GCGEA Member', 'code' => 'gcgea_member', 'description' => 'Self-service account for a GCGEA member — view and create their own loan and benefit applications only.', 'is_system_role' => true, 'permissions' => $gcgeaMember],
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

        // The explicit ids above (needed so this seeder stays idempotent across
        // re-runs) bypass Postgres' auto-increment sequence — resync it so a
        // role created afterward via the admin UI doesn't collide with a seeded id.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('roles_id_seq', (SELECT MAX(id) FROM roles))");
        }
    }
}
