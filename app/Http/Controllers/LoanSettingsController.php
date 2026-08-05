<?php

namespace App\Http\Controllers;

use App\Http\Resources\LoanSettingResource;
use App\Models\LoanSetting;
use Illuminate\Http\Request;

class LoanSettingsController extends Controller
{
    /** No permission gate — every authenticated user needs to read the effective minimum-membership-months/reloan policy (mirrors /loan-types). */
    public function show()
    {
        return new LoanSettingResource(LoanSetting::current());
    }

    public function update(Request $request)
    {
        if (! $request->user()->hasPermission('settings.loan')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $roundingAliases = [
            'Round to nearest centavo' => 'Nearest Centavo',
            'Round to nearest peso' => 'Nearest Peso',
            'Round up' => 'Round Up',
            'Round down' => 'Round Down',
        ];
        $request->merge([
            'roundingRule' => $roundingAliases[$request->input('roundingRule')] ?? $request->input('roundingRule'),
        ]);

        $data = $request->validate([
            'minimumMembershipMonths' => ['required', 'integer', 'min:0'],
            'defaultPenaltyRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:365'],
            'defaultPaymentMethod' => ['required', 'in:Payroll Deduction,Cash,Bank Transfer,Check'],
            'roundingRule' => ['required', 'in:Nearest Centavo,Nearest Peso,Round Up,Round Down'],
            'allowEligibilityOverride' => ['required', 'boolean'],
            'requireApproval' => ['required', 'boolean'],
            'requireReleaseConfirmation' => ['required', 'boolean'],
            'allowPartialPayment' => ['required', 'boolean'],
            'allowAdvancePayment' => ['required', 'boolean'],
            'allowLoanRestructuring' => ['required', 'boolean'],
            'requirePaidContributions' => ['required', 'boolean'],
            'minimumPaidContributionMonths' => ['required', 'integer', 'min:0'],
            'requiredMonthlyDuesAmount' => ['required', 'numeric', 'min:0'],
            'requireConsecutiveContributionMonths' => ['required', 'boolean'],
            'applyContributionRuleToReloan' => ['required', 'boolean'],
            'lockFirstSolidarityLoan' => ['required', 'boolean'],
            'firstSolidarityLoanAmount' => ['required', 'numeric', 'min:1', 'max:999999999.99'],
            'reloanEnabled' => ['required', 'boolean'],
            'reloanAllowAfterFullyPaid' => ['required', 'boolean'],
            'reloanAllowWhileActive' => ['required', 'boolean'],
            'reloanMinPaidInstallments' => ['nullable', 'integer', 'min:0'],
            'reloanMinPaidPercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reloanRequireNoOverdue' => ['required', 'boolean'],
            'reloanRequireNoPenalty' => ['required', 'boolean'],
            'reloanDeductPreviousBalance' => ['required', 'boolean'],
            'reloanMaxConcurrentActiveLoans' => ['required', 'integer', 'min:1'],
            'reloanRequireNewPayslip' => ['required', 'boolean'],
            'reloanRequireNewAuthorization' => ['required', 'boolean'],
            'reloanRequireNewPromissoryNote' => ['required', 'boolean'],
            'reloanRequireFinalApproval' => ['required', 'boolean'],
            'reloanRequireBoardResolutionAboveLimit' => ['required', 'boolean'],
        ]);

        $setting = LoanSetting::current();
        $setting->update([
            'minimum_membership_months' => $data['minimumMembershipMonths'],
            'default_penalty_rate' => $data['defaultPenaltyRate'],
            'grace_period_days' => $data['gracePeriodDays'],
            'default_payment_method' => $data['defaultPaymentMethod'],
            'rounding_rule' => $data['roundingRule'],
            'allow_eligibility_override' => $data['allowEligibilityOverride'],
            'require_approval' => $data['requireApproval'],
            'require_release_confirmation' => $data['requireReleaseConfirmation'],
            'allow_partial_payment' => $data['allowPartialPayment'],
            'allow_advance_payment' => $data['allowAdvancePayment'],
            'allow_loan_restructuring' => $data['allowLoanRestructuring'],
            'require_paid_contributions' => $data['requirePaidContributions'],
            'minimum_paid_contribution_months' => $data['minimumPaidContributionMonths'],
            'required_monthly_dues_amount' => $data['requiredMonthlyDuesAmount'],
            'require_consecutive_contribution_months' => $data['requireConsecutiveContributionMonths'],
            'apply_contribution_rule_to_reloan' => $data['applyContributionRuleToReloan'],
            'lock_first_solidarity_loan' => $data['lockFirstSolidarityLoan'],
            'first_solidarity_loan_amount' => $data['firstSolidarityLoanAmount'],
            'reloan_enabled' => $data['reloanEnabled'],
            'reloan_allow_after_fully_paid' => $data['reloanAllowAfterFullyPaid'],
            'reloan_allow_while_active' => $data['reloanAllowWhileActive'],
            'reloan_min_paid_installments' => $data['reloanMinPaidInstallments'] ?? null,
            'reloan_min_paid_percentage' => $data['reloanMinPaidPercentage'] ?? null,
            'reloan_require_no_overdue' => $data['reloanRequireNoOverdue'],
            'reloan_require_no_penalty' => $data['reloanRequireNoPenalty'],
            'reloan_deduct_previous_balance' => $data['reloanDeductPreviousBalance'],
            'reloan_max_concurrent_active_loans' => $data['reloanMaxConcurrentActiveLoans'],
            'reloan_require_new_payslip' => $data['reloanRequireNewPayslip'],
            'reloan_require_new_authorization' => $data['reloanRequireNewAuthorization'],
            'reloan_require_new_promissory_note' => $data['reloanRequireNewPromissoryNote'],
            'reloan_require_final_approval' => $data['reloanRequireFinalApproval'],
            'reloan_require_board_resolution_above_limit' => $data['reloanRequireBoardResolutionAboveLimit'],
            'updated_by' => $request->user()->full_name,
        ]);

        return new LoanSettingResource($setting->fresh());
    }
}
