<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\MembershipApprovalSettingController;
use App\Http\Controllers\Admin\WorkflowDefinitionController;
use App\Http\Controllers\AnnualBudgetController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BenefitApplicationController;
use App\Http\Controllers\BenefitTypeController;
use App\Http\Controllers\BulkPayrollDeductionController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\ContributionFundController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeductionController;
use App\Http\Controllers\DeductionTypeController;
use App\Http\Controllers\DisbursementController;
use App\Http\Controllers\EmploymentStatusController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\LegacyLoanImportController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanDocumentController;
use App\Http\Controllers\LoanImportHistoryController;
use App\Http\Controllers\LoanPaymentController;
use App\Http\Controllers\LoanSettingsController;
use App\Http\Controllers\LoanTypeController;
use App\Http\Controllers\LookupsController;
use App\Http\Controllers\ManualPayrollDeductionController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberDocumentController;
use App\Http\Controllers\MemberImportController;
use App\Http\Controllers\MonthlyDisbursementReportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\PayrollImportController;
use App\Http\Controllers\ReloanController;
use App\Http\Controllers\RemittanceBreakdownReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SystemBackupController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Deliberately outside auth:sanctum — this is the frontend's session-restore check on
    // every page load, so a guest visiting the login page hits it with no session cookie at
    // all. Sanctum's statefulApi() middleware (bootstrap/app.php) still resolves $request->user()
    // from the session when one exists; auth:sanctum only decides whether to abort with 401
    // when it doesn't. Answering 200+null instead of a hard 401 for "never logged in" avoids
    // both a spurious browser console error on every guest page load and an unnecessary
    // gcgea:session-expired event firing before anyone has actually logged in.
    Route::middleware('security.timeout')->get('/me', [AuthController::class, 'me']);

    Route::middleware(['auth:sanctum', 'security.timeout'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware(['auth:sanctum', 'security.timeout', 'password.changed', 'maintenance'])->group(function () {
    Route::get('/global-search', GlobalSearchController::class);
    Route::post('/report-exports/pdf', [ReportExportController::class, 'pdf']);
    Route::post('/report-exports/excel', [ReportExportController::class, 'excel']);

    // Bundles roles/all, users/all, offices/all, loan-types, benefit-types, deduction-types,
    // and loan-settings into one response — see LookupsController for why.
    Route::get('/lookups', [LookupsController::class, 'index']);

    Route::get('/system-settings', [SystemSettingController::class, 'index']);
    Route::get('/appearance-settings', [SystemSettingController::class, 'appearance']);
    Route::get('/system-storage-usage', [SystemSettingController::class, 'storageUsage']);
    Route::put('/system-settings/{section}', [SystemSettingController::class, 'update']);
    Route::get('/system-backups', [SystemBackupController::class, 'index']);
    Route::post('/system-backups', [SystemBackupController::class, 'store']);
    Route::get('/system-backups/{backup}/download', [SystemBackupController::class, 'download']);
    Route::delete('/system-backups/{backup}', [SystemBackupController::class, 'destroy']);
    Route::get('/employment-statuses', [EmploymentStatusController::class, 'index']);
    Route::post('/employment-statuses', [EmploymentStatusController::class, 'store']);
    Route::put('/employment-statuses/{employmentStatus}', [EmploymentStatusController::class, 'update']);
    Route::patch('/employment-statuses/{employmentStatus}/toggle-status', [EmploymentStatusController::class, 'toggleStatus']);

    // Offices
    Route::get('/offices/all', [OfficeController::class, 'all']);
    Route::get('/offices', [OfficeController::class, 'index']);
    Route::post('/offices', [OfficeController::class, 'store']);
    Route::put('/offices/{office}', [OfficeController::class, 'update']);
    Route::patch('/offices/{office}/toggle-status', [OfficeController::class, 'toggleStatus']);

    // Members
    Route::get('/members/all', [MemberController::class, 'all']);
    Route::get('/members/archived', [MemberController::class, 'archived']);
    Route::get('/members', [MemberController::class, 'index']);
    Route::post('/members', [MemberController::class, 'store']);
    Route::get('/members/{member}', [MemberController::class, 'show']);
    Route::put('/members/{member}', [MemberController::class, 'update']);
    Route::patch('/members/{member}/membership-status', [MemberController::class, 'updateMembershipStatus']);
    Route::post('/members/{member}/submit', [MemberController::class, 'submit']);
    Route::post('/members/{member}/approve', [MemberController::class, 'approve']);
    Route::post('/members/{member}/reject', [MemberController::class, 'reject']);
    Route::post('/members/{member}/archive', [MemberController::class, 'archive']);
    Route::post('/members/{member}/restore', [MemberController::class, 'restore']);
    Route::post('/members/{member}/photo', [MemberDocumentController::class, 'storePhoto']);
    Route::delete('/members/{member}/photo', [MemberDocumentController::class, 'destroyPhoto']);
    Route::post('/members/{member}/documents', [MemberDocumentController::class, 'storeDocument']);
    Route::delete('/members/{member}/documents/{document}', [MemberDocumentController::class, 'destroyDocument']);
    Route::get('/members/{member}/loan-eligibility', [MemberController::class, 'loanEligibility']);

    // Member Import
    Route::middleware('permission:members.review')->get('/member-imports/pending-review', [MemberImportController::class, 'pendingReview']);
    Route::middleware('permission:member_import.view')->group(function () {
        Route::get('/member-imports', [MemberImportController::class, 'index']);
        Route::get('/member-imports/all', [MemberImportController::class, 'all']);
        Route::get('/member-imports/{batch:token}', [MemberImportController::class, 'show']);
        Route::get('/member-imports/{batch:token}/report', [MemberImportController::class, 'downloadReport']);
    });
    Route::middleware('permission:member_import.create')->group(function () {
        Route::post('/member-imports', [MemberImportController::class, 'upload']);
        Route::post('/member-imports/{batch:token}/select-sheet', [MemberImportController::class, 'selectSheet']);
        Route::post('/member-imports/{batch:token}/preview', [MemberImportController::class, 'preview']);
        Route::post('/member-imports/{batch:token}/commit', [MemberImportController::class, 'commit']);
        Route::post('/member-imports/{batch:token}/undo', [MemberImportController::class, 'undo']);
    });
    Route::middleware('permission:member_import.resolve_duplicates')->post('/member-imports/{batch:token}/resolve-duplicates', [MemberImportController::class, 'resolveDuplicates']);
    Route::middleware('permission:member_import.manage_offices')->post('/member-imports/{batch:token}/resolve-office', [MemberImportController::class, 'resolveOffice']);

    // Loan Types
    Route::get('/loan-types', [LoanTypeController::class, 'index']);
    Route::post('/loan-types', [LoanTypeController::class, 'store']);
    Route::put('/loan-types/{loanType}', [LoanTypeController::class, 'update']);
    Route::delete('/loan-types/{loanType}', [LoanTypeController::class, 'destroy']);

    // Loan Settings (minimum membership duration + Reloan Policy)
    Route::get('/loan-settings', [LoanSettingsController::class, 'show']);
    Route::put('/loan-settings', [LoanSettingsController::class, 'update']);

    // Benefit Types
    Route::get('/benefit-types', [BenefitTypeController::class, 'index']);
    Route::post('/benefit-types', [BenefitTypeController::class, 'store']);
    Route::put('/benefit-types/{benefitType}', [BenefitTypeController::class, 'update']);
    Route::delete('/benefit-types/{benefitType}', [BenefitTypeController::class, 'destroy']);

    // Contributions
    Route::get('/contributions/all', [ContributionController::class, 'all']);
    Route::get('/contributions/periods', [ContributionController::class, 'periods']);
    Route::get('/contributions/summary', [ContributionController::class, 'summary']);
    Route::get('/contributions/check-duplicate', [ContributionController::class, 'checkDuplicate']);
    Route::post('/contributions/check-duplicates', [ContributionController::class, 'checkDuplicates']);
    Route::get('/contributions', [ContributionController::class, 'index']);
    Route::post('/contributions', [ContributionController::class, 'store']);
    Route::post('/contributions/bulk', [ContributionController::class, 'bulkStore']);
    Route::get('/contributions/{contribution}', [ContributionController::class, 'show']);
    Route::put('/contributions/{contribution}', [ContributionController::class, 'update']);
    Route::post('/contributions/{contribution}/void', [ContributionController::class, 'void']);

    // Dynamic Monthly Dues fund allocation
    Route::get('/contribution-funds', [ContributionFundController::class, 'index']);
    Route::post('/contribution-funds', [ContributionFundController::class, 'store']);
    Route::put('/contribution-funds/{contributionFund}', [ContributionFundController::class, 'update']);
    Route::delete('/contribution-funds/{contributionFund}', [ContributionFundController::class, 'destroy']);
    Route::get('/reports/fund-allocations', [ContributionFundController::class, 'report']);
    Route::get('/reports/remittance-breakdown', RemittanceBreakdownReportController::class);
    Route::get('/annual-budgets', [AnnualBudgetController::class, 'index']);
    Route::get('/annual-budgets/all', [AnnualBudgetController::class, 'all']);
    Route::get('/annual-budgets/id/{annualBudget}', [AnnualBudgetController::class, 'showById']);
    Route::get('/annual-budgets/{year}', [AnnualBudgetController::class, 'show']);
    Route::put('/annual-budgets/{year}', [AnnualBudgetController::class, 'update']);
    Route::post('/annual-budgets/{year}/copy-previous', [AnnualBudgetController::class, 'copyPrevious']);
    Route::post('/annual-budgets/{year}/submit', [AnnualBudgetController::class, 'submit']);
    Route::get('/disbursements', [DisbursementController::class, 'index']);
    Route::get('/disbursements/all', [DisbursementController::class, 'all']);
    Route::post('/disbursements', [DisbursementController::class, 'store']);
    Route::get('/disbursements/{disbursement}', [DisbursementController::class, 'show']);
    Route::put('/disbursements/{disbursement}', [DisbursementController::class, 'update']);
    Route::post('/disbursements/{disbursement}/submit', [DisbursementController::class, 'submit']);
    Route::post('/disbursements/{disbursement}/pay', [DisbursementController::class, 'markPaid']);
    Route::post('/disbursements/{disbursement}/void', [DisbursementController::class, 'void']);
    Route::get('/reports/monthly-disbursements', MonthlyDisbursementReportController::class);

    // Deduction Types
    Route::get('/deduction-types', [DeductionTypeController::class, 'index']);
    Route::post('/deduction-types', [DeductionTypeController::class, 'store']);
    Route::put('/deduction-types/{deductionType}', [DeductionTypeController::class, 'update']);
    Route::patch('/deduction-types/{deductionType}/toggle-status', [DeductionTypeController::class, 'toggleStatus']);

    // Deductions
    Route::middleware('permission:deductions.view')->group(function () {
        Route::get('/deductions', [DeductionController::class, 'index']);
        Route::get('/deductions/all', [DeductionController::class, 'all']);
    });
    Route::middleware('permission:deductions.void')->post('/deductions/{deduction}/void', [DeductionController::class, 'void']);

    // Payroll Import
    Route::middleware('permission:payroll.history.view')->group(function () {
        Route::get('/payroll-imports', [PayrollImportController::class, 'index']);
        Route::get('/payroll-imports/{batch:token}', [PayrollImportController::class, 'show']);
        Route::get('/payroll-imports/{batch:token}/report', [PayrollImportController::class, 'downloadReport']);
    });
    Route::middleware('permission:payroll.import.view')->group(function () {
        Route::post('/payroll-imports', [PayrollImportController::class, 'upload']);
        Route::post('/payroll-imports/{batch:token}/select-sheet', [PayrollImportController::class, 'selectSheet']);
        Route::post('/payroll-imports/{batch:token}/preview', [PayrollImportController::class, 'preview']);
        Route::post('/payroll-imports/{batch:token}/commit', [PayrollImportController::class, 'commit']);
    });
    Route::middleware('permission:payroll.import.rollback')->post('/payroll-imports/{batch:token}/rollback', [PayrollImportController::class, 'rollback']);

    Route::middleware('permission:payroll.manual.view')->group(function () {
        Route::get('/payroll-deductions/manual/reference', [ManualPayrollDeductionController::class, 'reference']);
        Route::get('/payroll-deductions/manual/members/{member}', [ManualPayrollDeductionController::class, 'member']);
    });
    Route::middleware('permission:payroll.manual.create')->post('/payroll-deductions/manual', [ManualPayrollDeductionController::class, 'store']);
    Route::middleware('permission:payroll.manual.post')->post('/payroll-deductions/manual/{payrollDeduction}/post', [ManualPayrollDeductionController::class, 'post']);

    Route::middleware('permission:payroll.bulk.view')->group(function () {
        Route::get('/payroll-deductions/bulk/reference', [BulkPayrollDeductionController::class, 'reference']);
        Route::post('/payroll-deductions/bulk/members/context', [BulkPayrollDeductionController::class, 'membersContext']);
    });
    Route::middleware('permission:payroll.bulk.create')->post('/payroll-deductions/bulk', [BulkPayrollDeductionController::class, 'store']);
    Route::middleware('permission:payroll.bulk.post')->post('/payroll-deductions/bulk/{reference}/post', [BulkPayrollDeductionController::class, 'post']);

    // Loans
    Route::middleware('permission:loan_payments.import')->group(function () {
        Route::post('/legacy-loan-imports', [LegacyLoanImportController::class, 'upload']);
        Route::post('/legacy-loan-imports/{token}/commit', [LegacyLoanImportController::class, 'commit']);
        Route::get('/loan-imports/history', [LoanImportHistoryController::class, 'index']);
        Route::get('/loan-imports/history/all', [LoanImportHistoryController::class, 'all']);
        Route::post('/loan-imports/history/{batch:token}/undo', [LoanImportHistoryController::class, 'undo']);
        Route::get('/loan-imports/history/{batch:token}', [LoanImportHistoryController::class, 'show']);
    });
    Route::get('/loans/all', [LoanController::class, 'all']);
    Route::get('/loans', [LoanController::class, 'index']);
    Route::post('/loans', [LoanController::class, 'store']);
    Route::post('/loans/{loan}/documents', [LoanDocumentController::class, 'store']);
    Route::get('/loans/{loan}', [LoanController::class, 'show']);
    Route::put('/loans/{loan}', [LoanController::class, 'update']);
    Route::delete('/loans/{loan}', [LoanController::class, 'destroy']);
    Route::get('/loans/{loan}/schedule', [LoanController::class, 'schedule']);
    Route::post('/loans/{loan}/review', [LoanController::class, 'review']);
    Route::post('/loans/{loan}/approve', [LoanController::class, 'approve']);
    Route::post('/loans/{loan}/reject', [LoanController::class, 'reject']);
    Route::post('/loans/{loan}/return', [LoanController::class, 'returnForRevision']);
    Route::post('/loans/{loan}/release', [LoanController::class, 'release']);
    Route::get('/loans/{loan}/reloan-eligibility', [ReloanController::class, 'eligibility']);
    Route::post('/loans/{loan}/reloan', [ReloanController::class, 'createDraft']);

    // Loan Payments
    Route::get('/loan-payments/all', [LoanPaymentController::class, 'all']);
    Route::get('/loan-payments', [LoanPaymentController::class, 'index']);
    Route::post('/loan-payments', [LoanPaymentController::class, 'store']);

    // Benefits
    Route::get('/benefits/all', [BenefitApplicationController::class, 'all']);
    Route::get('/benefits', [BenefitApplicationController::class, 'index']);
    Route::post('/benefits', [BenefitApplicationController::class, 'store']);
    Route::get('/benefits/{benefit}', [BenefitApplicationController::class, 'show']);
    Route::put('/benefits/{benefit}', [BenefitApplicationController::class, 'update']);
    Route::delete('/benefits/{benefit}', [BenefitApplicationController::class, 'destroy']);
    Route::post('/benefits/{benefit}/review', [BenefitApplicationController::class, 'review']);
    Route::post('/benefits/{benefit}/approve', [BenefitApplicationController::class, 'approve']);
    Route::post('/benefits/{benefit}/reject', [BenefitApplicationController::class, 'reject']);
    Route::post('/benefits/{benefit}/return', [BenefitApplicationController::class, 'returnForRevision']);
    Route::post('/benefits/{benefit}/release', [BenefitApplicationController::class, 'release']);

    // Approval Workflow — generic engine
    Route::get('/my-approvals', [ApprovalController::class, 'index']);
    Route::get('/approvals/{subjectType}/{subjectId}/history', [ApprovalController::class, 'history']);
    Route::post('/approvals/{subjectType}/{subjectId}/act', [ApprovalController::class, 'act']);

    // Approval Workflow — admin configuration
    Route::middleware('permission:approval_workflow.view')->group(function () {
        Route::get('/admin/workflow-definitions', [WorkflowDefinitionController::class, 'index']);
        Route::get('/admin/workflow-definitions/{workflowDefinition}', [WorkflowDefinitionController::class, 'show']);
    });
    Route::middleware('permission:approval_workflow.configure')->group(function () {
        Route::put('/admin/workflow-definitions/{workflowDefinition}', [WorkflowDefinitionController::class, 'update']);
        Route::put('/admin/workflow-definitions/{workflowDefinition}/stages', [WorkflowDefinitionController::class, 'updateStages']);
    });

    Route::get('/settings/membership-approval', [MembershipApprovalSettingController::class, 'show']);
    Route::put('/settings/membership-approval', [MembershipApprovalSettingController::class, 'update']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // Audit Logs
    Route::middleware('permission:audit_logs.view')->get('/admin/audit-logs', [AuditLogController::class, 'index']);
    Route::middleware('permission:audit_logs.export')->get('/admin/audit-logs/export', [AuditLogController::class, 'export']);

    // Roles — /all is also used by the Approval Workflow admin's approver-by-role picker
    Route::get('/roles/all', [RoleController::class, 'all']);
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{role}', [RoleController::class, 'show']);
    Route::put('/roles/{role}', [RoleController::class, 'update']);
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
    Route::post('/roles/{role}/duplicate', [RoleController::class, 'duplicate']);
    Route::patch('/roles/{role}/toggle-status', [RoleController::class, 'toggleStatus']);
    Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

    // Users — /all is also used by the Approval Workflow admin's approver-by-user picker
    Route::get('/users/all', [UserController::class, 'all']);
    Route::post('/users/me/avatar', [UserController::class, 'updateMyAvatar']);
    Route::delete('/users/me/avatar', [UserController::class, 'removeMyAvatar']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions']);
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    Route::get('/users/{user}/login-history', [UserController::class, 'loginHistory']);

    // Dashboard
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/monthly-releases', [DashboardController::class, 'monthlyLoanReleases']);
    Route::get('/dashboard/monthly-collections', [DashboardController::class, 'monthlyCollections']);
    Route::get('/dashboard/loan-status', [DashboardController::class, 'loanStatusDistribution']);
    Route::get('/dashboard/benefit-distribution', [DashboardController::class, 'benefitDistributionByType']);
    Route::get('/dashboard/members-per-office', [DashboardController::class, 'membersPerOffice']);
    Route::get('/dashboard/membership-growth', [DashboardController::class, 'membershipGrowth']);
    Route::get('/dashboard/recent-loans', [DashboardController::class, 'recentLoanApplications']);
    Route::get('/dashboard/recent-payments', [DashboardController::class, 'recentPayments']);
    Route::get('/dashboard/upcoming-due', [DashboardController::class, 'upcomingDueLoans']);
    Route::get('/dashboard/overdue-loans', [DashboardController::class, 'overdueLoans']);
    Route::get('/dashboard/recent-benefits', [DashboardController::class, 'recentBenefitApplications']);
    Route::get('/dashboard/recent-members', [DashboardController::class, 'recentlyAddedMembers']);
    Route::get('/dashboard/incomplete-profiles', [DashboardController::class, 'incompleteProfiles']);
});
