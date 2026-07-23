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
                    ['members.export', 'Export Members', 'Export member records'],
                    ['members.print', 'Print Members', 'Print member list/profile'],
                    ['members.review', 'Review Members', 'Review submitted member registrations'],
                    ['members.approve', 'Approve Members', 'Approve member registrations'],
                    ['members.reject', 'Reject Members', 'Reject member registrations'],
                    ['members.auto_approve', 'Auto Approve Members', 'Automatically approve new and imported members'],
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
                    ['loans.reloan', 'Initiate Reloan', 'Create a reloan application from an existing, eligible loan'],
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
            'approval_workflow' => [
                'label' => 'Approval Workflow',
                'permissions' => [
                    ['approval_workflow.view', 'View Approval Workflow', 'View workflow stage configuration'],
                    ['approval_workflow.configure', 'Configure Approval Workflow', 'Create/edit workflow stage assignments'],
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
            'member_import' => [
                'label' => 'Member Import',
                'permissions' => [
                    ['member_import.view', 'View Member Imports', 'View member import history, batch detail, and error/audit reports'],
                    ['member_import.create', 'Run Member Import', 'Upload a workbook, select a worksheet, map columns, preview/validate, and commit a member import batch'],
                    ['member_import.resolve_duplicates', 'Resolve Member Duplicates', 'Act on possible/probable/exact duplicate-member matches during import, including merge decisions'],
                    ['member_import.manage_offices', 'Manage Import Office Mapping', 'Create a new office or save an office alias while resolving unmapped office names during import'],
                ],
            ],
            'payroll_manual' => [
                'label' => 'Manual Payroll Entry',
                'permissions' => [
                    ['payroll.manual.view', 'View Manual Payroll', 'View manual payroll deduction entries'],
                    ['payroll.manual.create', 'Create Manual Payroll', 'Create and save payroll deduction drafts'],
                    ['payroll.manual.edit', 'Edit Manual Payroll', 'Edit draft payroll deductions'],
                    ['payroll.manual.post', 'Post Manual Payroll', 'Post payroll deductions to financial ledgers'],
                    ['payroll.manual.delete', 'Delete Manual Payroll', 'Delete draft payroll deductions'],
                    ['payroll.manual.print', 'Print Manual Payroll', 'Print payroll deduction records'],
                    ['payroll.manual.override', 'Override Payroll Amounts', 'Override configured dues and Pabaon amounts'],
                ],
            ],
            'payroll_bulk' => [
                'label' => 'Bulk Payroll Entry',
                'permissions' => [
                    ['payroll.bulk.view', 'View Bulk Payroll', 'View bulk payroll deduction entries'],
                    ['payroll.bulk.create', 'Create Bulk Payroll', 'Load members and save bulk payroll deduction drafts'],
                    ['payroll.bulk.edit', 'Edit Bulk Payroll', 'Edit draft bulk payroll deductions'],
                    ['payroll.bulk.post', 'Post Bulk Payroll', 'Post bulk payroll deductions to financial ledgers'],
                    ['payroll.bulk.delete', 'Delete Bulk Payroll', 'Delete draft bulk payroll deductions'],
                    ['payroll.bulk.print', 'Print Bulk Payroll', 'Print bulk payroll deduction records'],
                    ['payroll.bulk.override', 'Override Bulk Payroll Amounts', 'Override configured dues and Pabaon amounts in bulk entry'],
                ],
            ],
            'payroll_import' => [
                'label' => 'Payroll Import',
                'permissions' => [
                    ['payroll.import.view', 'View Payroll Import', 'Access the Payroll Import screen and run imports'],
                    ['payroll.import.rollback', 'Rollback Payroll Imports', 'Reverse a committed payroll import batch'],
                ],
            ],
            'payroll_history' => [
                'label' => 'Payroll History',
                'permissions' => [
                    ['payroll.history.view', 'View Payroll History', 'View unified payroll deduction history — manual, bulk, and imported'],
                ],
            ],
            'deduction_types' => [
                'label' => 'Deduction Types',
                'permissions' => [
                    ['deduction_types.view', 'View Deduction Types', 'View configured deduction types'],
                    ['deduction_types.create', 'Create Deduction Types', 'Add deduction types'],
                    ['deduction_types.update', 'Update Deduction Types', 'Edit deduction types'],
                    ['deduction_types.deactivate', 'Deactivate Deduction Types', 'Enable/disable a deduction type'],
                ],
            ],
            'deductions' => [
                'label' => 'Deductions',
                'permissions' => [
                    ['deductions.view', 'View Deductions', 'View deduction records'],
                    ['deductions.void', 'Void Deductions', 'Void posted deduction records'],
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
