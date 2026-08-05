<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row (id = 1) — the global minimum-membership-months
 * floor for all loans, plus the Reloan Policy block. Always read/written
 * through current(), never queried directly.
 */
class LoanSetting extends Model
{
    protected $fillable = [
        'minimum_membership_months',
        'default_penalty_rate',
        'grace_period_days',
        'default_payment_method',
        'rounding_rule',
        'allow_eligibility_override',
        'require_approval',
        'require_release_confirmation',
        'allow_partial_payment',
        'allow_advance_payment',
        'allow_loan_restructuring',
        'require_paid_contributions',
        'minimum_paid_contribution_months',
        'required_monthly_dues_amount',
        'require_consecutive_contribution_months',
        'apply_contribution_rule_to_reloan',
        'lock_first_solidarity_loan',
        'first_solidarity_loan_amount',
        'reloan_enabled',
        'reloan_allow_after_fully_paid',
        'reloan_allow_while_active',
        'reloan_min_paid_installments',
        'reloan_min_paid_percentage',
        'reloan_require_no_overdue',
        'reloan_require_no_penalty',
        'reloan_deduct_previous_balance',
        'reloan_max_concurrent_active_loans',
        'reloan_require_new_payslip',
        'reloan_require_new_authorization',
        'reloan_require_new_promissory_note',
        'reloan_require_final_approval',
        'reloan_require_board_resolution_above_limit',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_membership_months' => 'integer',
            'default_penalty_rate' => 'decimal:2',
            'grace_period_days' => 'integer',
            'allow_eligibility_override' => 'boolean',
            'require_approval' => 'boolean',
            'require_release_confirmation' => 'boolean',
            'allow_partial_payment' => 'boolean',
            'allow_advance_payment' => 'boolean',
            'allow_loan_restructuring' => 'boolean',
            'require_paid_contributions' => 'boolean',
            'minimum_paid_contribution_months' => 'integer',
            'required_monthly_dues_amount' => 'decimal:2',
            'require_consecutive_contribution_months' => 'boolean',
            'apply_contribution_rule_to_reloan' => 'boolean',
            'lock_first_solidarity_loan' => 'boolean',
            'first_solidarity_loan_amount' => 'decimal:2',
            'reloan_enabled' => 'boolean',
            'reloan_allow_after_fully_paid' => 'boolean',
            'reloan_allow_while_active' => 'boolean',
            'reloan_min_paid_installments' => 'integer',
            'reloan_min_paid_percentage' => 'decimal:2',
            'reloan_require_no_overdue' => 'boolean',
            'reloan_require_no_penalty' => 'boolean',
            'reloan_deduct_previous_balance' => 'boolean',
            'reloan_max_concurrent_active_loans' => 'integer',
            'reloan_require_new_payslip' => 'boolean',
            'reloan_require_new_authorization' => 'boolean',
            'reloan_require_new_promissory_note' => 'boolean',
            'reloan_require_final_approval' => 'boolean',
            'reloan_require_board_resolution_above_limit' => 'boolean',
        ];
    }

    /**
     * firstOrCreate() only populates the attributes it's given on the
     * insert path — DB-level column defaults aren't reflected back into the
     * in-memory model without a re-fetch. Pass every default explicitly so
     * current() is correct on the very first call against a fresh install,
     * not just after a subsequent reload.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'minimum_membership_months' => 6,
            'default_penalty_rate' => 2,
            'grace_period_days' => 5,
            'default_payment_method' => 'Payroll Deduction',
            'rounding_rule' => 'Nearest Centavo',
            'allow_eligibility_override' => true,
            'require_approval' => true,
            'require_release_confirmation' => true,
            'allow_partial_payment' => true,
            'allow_advance_payment' => true,
            'allow_loan_restructuring' => false,
            'require_paid_contributions' => true,
            'minimum_paid_contribution_months' => 6,
            'required_monthly_dues_amount' => 100,
            'require_consecutive_contribution_months' => true,
            'apply_contribution_rule_to_reloan' => true,
            'lock_first_solidarity_loan' => true,
            'first_solidarity_loan_amount' => 20000,
            'reloan_enabled' => true,
            'reloan_allow_after_fully_paid' => true,
            'reloan_allow_while_active' => true,
            'reloan_min_paid_installments' => 6,
            'reloan_require_no_overdue' => true,
            'reloan_require_no_penalty' => true,
            'reloan_deduct_previous_balance' => false,
            'reloan_max_concurrent_active_loans' => 1,
            'reloan_require_new_payslip' => true,
            'reloan_require_new_authorization' => true,
            'reloan_require_new_promissory_note' => true,
            'reloan_require_final_approval' => true,
            'reloan_require_board_resolution_above_limit' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reloanPolicySnapshot(): array
    {
        return [
            'reloanEnabled' => $this->reloan_enabled,
            'reloanAllowAfterFullyPaid' => $this->reloan_allow_after_fully_paid,
            'reloanAllowWhileActive' => $this->reloan_allow_while_active,
            'reloanMinPaidInstallments' => $this->reloan_min_paid_installments,
            'reloanMinPaidPercentage' => $this->reloan_min_paid_percentage,
            'reloanRequireNoOverdue' => $this->reloan_require_no_overdue,
            'reloanRequireNoPenalty' => $this->reloan_require_no_penalty,
            'reloanDeductPreviousBalance' => $this->reloan_deduct_previous_balance,
            'reloanMaxConcurrentActiveLoans' => $this->reloan_max_concurrent_active_loans,
            'reloanRequireNewPayslip' => $this->reloan_require_new_payslip,
            'reloanRequireNewAuthorization' => $this->reloan_require_new_authorization,
            'reloanRequireNewPromissoryNote' => $this->reloan_require_new_promissory_note,
            'reloanRequireFinalApproval' => $this->reloan_require_final_approval,
            'reloanRequireBoardResolutionAboveLimit' => $this->reloan_require_board_resolution_above_limit,
        ];
    }
}
