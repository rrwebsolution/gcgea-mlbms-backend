<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BenefitApplicationController;
use App\Http\Controllers\BenefitTypeController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanPaymentController;
use App\Http\Controllers\LoanTypeController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberDocumentController;
use App\Http\Controllers\OfficeController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
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
    Route::post('/members/{member}/submit', [MemberController::class, 'submit']);
    Route::post('/members/{member}/archive', [MemberController::class, 'archive']);
    Route::post('/members/{member}/restore', [MemberController::class, 'restore']);
    Route::post('/members/{member}/photo', [MemberDocumentController::class, 'storePhoto']);
    Route::delete('/members/{member}/photo', [MemberDocumentController::class, 'destroyPhoto']);
    Route::post('/members/{member}/documents', [MemberDocumentController::class, 'storeDocument']);
    Route::delete('/members/{member}/documents/{document}', [MemberDocumentController::class, 'destroyDocument']);

    // Loan Types
    Route::get('/loan-types', [LoanTypeController::class, 'index']);
    Route::post('/loan-types', [LoanTypeController::class, 'store']);
    Route::put('/loan-types/{loanType}', [LoanTypeController::class, 'update']);
    Route::delete('/loan-types/{loanType}', [LoanTypeController::class, 'destroy']);

    // Benefit Types
    Route::get('/benefit-types', [BenefitTypeController::class, 'index']);
    Route::post('/benefit-types', [BenefitTypeController::class, 'store']);
    Route::put('/benefit-types/{benefitType}', [BenefitTypeController::class, 'update']);
    Route::delete('/benefit-types/{benefitType}', [BenefitTypeController::class, 'destroy']);

    // Contributions
    Route::get('/contributions/all', [ContributionController::class, 'all']);
    Route::get('/contributions/periods', [ContributionController::class, 'periods']);
    Route::get('/contributions/check-duplicate', [ContributionController::class, 'checkDuplicate']);
    Route::get('/contributions', [ContributionController::class, 'index']);
    Route::post('/contributions', [ContributionController::class, 'store']);
    Route::post('/contributions/bulk', [ContributionController::class, 'bulkStore']);
    Route::get('/contributions/{contribution}', [ContributionController::class, 'show']);
    Route::put('/contributions/{contribution}', [ContributionController::class, 'update']);
    Route::post('/contributions/{contribution}/void', [ContributionController::class, 'void']);

    // Loans
    Route::get('/loans/all', [LoanController::class, 'all']);
    Route::get('/loans', [LoanController::class, 'index']);
    Route::post('/loans', [LoanController::class, 'store']);
    Route::get('/loans/{loan}', [LoanController::class, 'show']);
    Route::put('/loans/{loan}', [LoanController::class, 'update']);
    Route::get('/loans/{loan}/schedule', [LoanController::class, 'schedule']);
    Route::get('/loans/{loan}/approval-history', [LoanController::class, 'approvalHistory']);

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

    // Dashboard
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
