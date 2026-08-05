<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'minimumMembershipMonths' => $this->minimum_membership_months,
            'defaultPenaltyRate' => (float) $this->default_penalty_rate,
            'gracePeriodDays' => $this->grace_period_days,
            'defaultPaymentMethod' => $this->default_payment_method,
            'roundingRule' => $this->rounding_rule,
            'allowEligibilityOverride' => $this->allow_eligibility_override,
            'requireApproval' => $this->require_approval,
            'requireReleaseConfirmation' => $this->require_release_confirmation,
            'allowPartialPayment' => $this->allow_partial_payment,
            'allowAdvancePayment' => $this->allow_advance_payment,
            'allowLoanRestructuring' => $this->allow_loan_restructuring,
            'requirePaidContributions' => $this->require_paid_contributions,
            'minimumPaidContributionMonths' => $this->minimum_paid_contribution_months,
            'requiredMonthlyDuesAmount' => (float) $this->required_monthly_dues_amount,
            'requireConsecutiveContributionMonths' => $this->require_consecutive_contribution_months,
            'applyContributionRuleToReloan' => $this->apply_contribution_rule_to_reloan,
            'lockFirstSolidarityLoan' => $this->lock_first_solidarity_loan,
            'firstSolidarityLoanAmount' => (float) $this->first_solidarity_loan_amount,
            'reloanPolicy' => [
                'reloanEnabled' => $this->reloan_enabled,
                'reloanAllowAfterFullyPaid' => $this->reloan_allow_after_fully_paid,
                'reloanAllowWhileActive' => $this->reloan_allow_while_active,
                'reloanMinPaidInstallments' => $this->reloan_min_paid_installments,
                'reloanMinPaidPercentage' => $this->reloan_min_paid_percentage !== null ? (float) $this->reloan_min_paid_percentage : null,
                'reloanRequireNoOverdue' => $this->reloan_require_no_overdue,
                'reloanRequireNoPenalty' => $this->reloan_require_no_penalty,
                'reloanDeductPreviousBalance' => $this->reloan_deduct_previous_balance,
                'reloanMaxConcurrentActiveLoans' => $this->reloan_max_concurrent_active_loans,
                'reloanRequireNewPayslip' => $this->reloan_require_new_payslip,
                'reloanRequireNewAuthorization' => $this->reloan_require_new_authorization,
                'reloanRequireNewPromissoryNote' => $this->reloan_require_new_promissory_note,
                'reloanRequireFinalApproval' => $this->reloan_require_final_approval,
                'reloanRequireBoardResolutionAboveLimit' => $this->reloan_require_board_resolution_above_limit,
            ],
            'updatedBy' => $this->updated_by,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
