<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Mirrors src/constants/permissions.ts (PERMISSION_GROUPS) on the frontend —
 * keep the two in sync when permissions are added or renamed.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $groups = [
            'dashboard' => [
                'label' => 'Dashboard',
                'permissions' => [
                    ['dashboard.view', 'View Dashboard', 'View dashboard summaries and charts'],
                ],
            ],
            'members' => [
                'label' => 'Members',
                'permissions' => [
                    ['members.view', 'View Members', 'View member records'],
                    ['members.create', 'Create Members', 'Register new members'],
                    ['members.update', 'Update Members', 'Edit member records'],
                    ['members.archive', 'Archive Members', 'Archive member records'],
                    ['members.restore', 'Restore Members', 'Restore archived members'],
                    ['members.import', 'Import Members', 'Bulk import members'],
                    ['members.export', 'Export Members', 'Export member records'],
                    ['members.print', 'Print Members', 'Print member list/profile'],
                ],
            ],
            'beneficiaries' => [
                'label' => 'Beneficiaries',
                'permissions' => [
                    ['beneficiaries.view', 'View Beneficiaries', 'View beneficiary records'],
                    ['beneficiaries.create', 'Add Beneficiaries', 'Add beneficiaries'],
                    ['beneficiaries.update', 'Update Beneficiaries', 'Edit beneficiaries'],
                    ['beneficiaries.delete', 'Delete Beneficiaries', 'Remove beneficiaries'],
                ],
            ],
            'offices' => [
                'label' => 'Offices',
                'permissions' => [
                    ['offices.view', 'View Offices', 'View office records'],
                    ['offices.create', 'Create Offices', 'Add offices'],
                    ['offices.update', 'Update Offices', 'Edit offices'],
                    ['offices.activate', 'Activate Offices', 'Reactivate an inactive office'],
                    ['offices.deactivate', 'Deactivate Offices', 'Deactivate an office'],
                ],
            ],
            'contributions' => [
                'label' => 'Contributions',
                'permissions' => [
                    ['contributions.view', 'View Contributions', 'View contribution records'],
                    ['contributions.create', 'Record Contributions', 'Record contributions'],
                    ['contributions.update', 'Update Contributions', 'Edit contributions'],
                    ['contributions.void', 'Void Contributions', 'Void posted contributions'],
                    ['contributions.bulk_create', 'Bulk Contribution Entry', 'Record contributions for multiple members at once'],
                    ['contributions.import', 'Import Contributions', 'Import payroll deductions'],
                    ['contributions.replace_duplicate', 'Replace Duplicate Contributions', 'Overwrite existing contribution records flagged as duplicates during import'],
                    ['contributions.export', 'Export Contributions', 'Export contribution reports'],
                    ['contributions.print', 'Print Contributions', 'Print receipts'],
                ],
            ],
            'loans' => [
                'label' => 'Loans',
                'permissions' => [
                    ['loans.view', 'View Loans', 'View loan applications'],
                    ['loans.create', 'Create Loans', 'Encode loan applications'],
                    ['loans.update', 'Update Loans', 'Edit loan applications'],
                    ['loans.submit', 'Submit Loans', 'Submit loan applications for review'],
                    ['loans.review', 'Review Loans', 'Review submitted loan applications'],
                    ['loans.recommend', 'Recommend Loans', 'Recommend a loan application for approval'],
                    ['loans.approve', 'Approve Loans', 'Approve loan applications'],
                    ['loans.reject', 'Reject Loans', 'Reject loan applications'],
                    ['loans.release', 'Release Loans', 'Release approved loans'],
                    ['loans.cancel', 'Cancel Loans', 'Cancel loan applications'],
                    ['loans.restructure', 'Restructure Loans', 'Restructure an existing loan'],
                    ['loans.override_eligibility', 'Override Loan Eligibility', 'Override failed eligibility checks with a documented reason'],
                    ['loans.print', 'Print Loans', 'Print loan documents'],
                    ['loans.export', 'Export Loans', 'Export loan reports'],
                ],
            ],
            'loan_payments' => [
                'label' => 'Loan Payments',
                'permissions' => [
                    ['loan_payments.view', 'View Loan Payments', 'View loan payments'],
                    ['loan_payments.create', 'Record Loan Payments', 'Record loan payments'],
                    ['loan_payments.update', 'Update Loan Payments', 'Edit loan payments'],
                    ['loan_payments.void', 'Void Loan Payments', 'Void posted payments'],
                    ['loan_payments.import', 'Import Loan Payments', 'Bulk import loan payments'],
                    ['loan_payments.print_receipt', 'Print Receipts', 'Print payment receipts'],
                    ['loan_payments.export', 'Export Loan Payments', 'Export payment records'],
                ],
            ],
            'benefits' => [
                'label' => 'Benefits',
                'permissions' => [
                    ['benefits.view', 'View Benefits', 'View benefit applications'],
                    ['benefits.create', 'Create Benefits', 'Encode benefit applications'],
                    ['benefits.update', 'Update Benefits', 'Edit benefit applications'],
                    ['benefits.submit', 'Submit Benefits', 'Submit benefit applications'],
                    ['benefits.review', 'Review Benefits', 'Review submitted benefit applications'],
                    ['benefits.approve', 'Approve Benefits', 'Approve benefit applications'],
                    ['benefits.reject', 'Reject Benefits', 'Reject benefit applications'],
                    ['benefits.release', 'Release Benefits', 'Release approved benefits'],
                    ['benefits.cancel', 'Cancel Benefits', 'Cancel benefit applications'],
                    ['benefits.override_eligibility', 'Override Benefit Eligibility', 'Override failed eligibility checks with a documented reason'],
                    ['benefits.print', 'Print Benefits', 'Print benefit documents'],
                    ['benefits.export', 'Export Benefits', 'Export benefit reports'],
                ],
            ],
            'reports' => [
                'label' => 'Reports',
                'permissions' => [
                    ['reports.view', 'View Reports', 'View the report center'],
                    ['reports.export', 'Export Reports', 'Export reports'],
                    ['reports.print', 'Print Reports', 'Print reports'],
                    ['reports.financial', 'Financial Reports', 'Access financial/collection reports'],
                    ['reports.audit', 'Audit Reports', 'Access audit-related reports'],
                    ['reports.member', 'Member Reports', 'Access member reports'],
                    ['reports.loan', 'Loan Reports', 'Access loan reports'],
                    ['reports.benefit', 'Benefit Reports', 'Access benefit reports'],
                    ['reports.contribution', 'Contribution Reports', 'Access contribution reports'],
                ],
            ],
            'users' => [
                'label' => 'Users',
                'permissions' => [
                    ['users.view', 'View Users', 'View system users'],
                    ['users.create', 'Create Users', 'Create user accounts'],
                    ['users.update', 'Update Users', 'Edit user accounts'],
                    ['users.activate', 'Activate Users', 'Reactivate a user account'],
                    ['users.deactivate', 'Deactivate Users', 'Deactivate a user account'],
                    ['users.reset_password', 'Reset Passwords', 'Reset user passwords'],
                    ['users.assign_role', 'Assign Roles', 'Assign primary/additional roles to a user'],
                    ['users.assign_permissions', 'Assign User Permissions', "Manage a user's direct allow/deny permissions"],
                    ['users.view_login_history', 'View Login History', "View a user's login history"],
                ],
            ],
            'roles' => [
                'label' => 'Roles & Permissions',
                'permissions' => [
                    ['roles.view', 'View Roles', 'View roles and permissions'],
                    ['roles.create', 'Create Roles', 'Create roles'],
                    ['roles.update', 'Update Roles', 'Edit roles'],
                    ['roles.duplicate', 'Duplicate Roles', 'Duplicate an existing role'],
                    ['roles.delete', 'Delete Roles', 'Delete custom roles'],
                    ['roles.assign_permissions', 'Assign Role Permissions', "Manage a role's permission matrix"],
                    ['roles.view_users', 'View Assigned Users', 'View users assigned to a role'],
                ],
            ],
            'audit_logs' => [
                'label' => 'Audit Logs',
                'permissions' => [
                    ['audit_logs.view', 'View Audit Logs', 'View system audit logs'],
                    ['audit_logs.export', 'Export Audit Logs', 'Export audit log records'],
                    ['audit_logs.view_sensitive_changes', 'View Sensitive Changes', 'View before/after values of sensitive fields'],
                ],
            ],
            'drafts' => [
                'label' => 'Drafts',
                'permissions' => [
                    ['drafts.view_own', 'View Own Drafts', 'View drafts you created'],
                    ['drafts.view_all', 'View All Drafts', 'View every draft in the system'],
                    ['drafts.create', 'Create Drafts', 'Save new drafts'],
                    ['drafts.update_own', 'Update Own Drafts', 'Edit and resave your own drafts'],
                    ['drafts.update_all', 'Update All Drafts', "Edit and resave any user's draft"],
                    ['drafts.delete_own', 'Delete Own Drafts', 'Delete your own drafts'],
                    ['drafts.delete_all', 'Delete All Drafts', "Delete any user's draft"],
                    ['drafts.duplicate', 'Duplicate Drafts', 'Duplicate an existing draft'],
                    ['drafts.transfer', 'Transfer Draft Ownership', 'Transfer a draft to another user'],
                    ['drafts.submit', 'Submit Drafts', 'Finalize a draft into a real record'],
                ],
            ],
            'settings' => [
                'label' => 'Settings',
                'permissions' => [
                    ['settings.view', 'View Settings', 'View system settings'],
                    ['settings.update', 'Update Settings', 'Update system settings'],
                    ['settings.general', 'General Settings', 'Manage general system settings'],
                    ['settings.organization', 'Organization Profile', 'Manage the organization profile'],
                    ['settings.numbering', 'Numbering Formats', 'Manage document numbering formats'],
                    ['settings.loan', 'Loan Settings', 'Manage default loan settings'],
                    ['settings.contribution', 'Contribution Settings', 'Manage default contribution settings'],
                    ['settings.benefit', 'Benefit Settings', 'Manage default benefit settings'],
                    ['settings.notification', 'Notification Settings', 'Manage notification preferences'],
                    ['settings.security', 'Security Settings', 'Manage password and session security policy'],
                    ['settings.backup', 'Backup Settings', 'Manage backup and restore settings'],
                    ['settings.appearance', 'Appearance Settings', 'Manage theme and appearance settings'],
                ],
            ],
        ];

        foreach ($groups as $group => $def) {
            foreach ($def['permissions'] as [$code, $label, $description]) {
                Permission::updateOrCreate(
                    ['code' => $code],
                    ['label' => $label, 'group' => $group, 'description' => $description]
                );
            }
        }
    }
}
