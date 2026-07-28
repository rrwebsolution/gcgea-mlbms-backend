<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Loan extends Model
{
    protected $fillable = [
        'application_number',
        'application_date',
        'member_id',
        'loan_type_id',
        'requested_amount',
        'approved_amount',
        'term_months',
        'interest_rate',
        'processing_fee',
        'purpose',
        'payment_method',
        'first_due_date',
        'maturity_date',
        'principal',
        'total_interest',
        'net_proceeds',
        'total_amount_payable',
        'monthly_amortization',
        'outstanding_balance',
        'principal_balance',
        'interest_balance',
        'status',
        'application_type',
        'previous_loan_id',
        'root_loan_id',
        'reloan_sequence',
        'current_net_take_home_pay',
        'assigned_officer',
        'eligibility',
        'eligibility_overridden',
        'eligibility_override_reason',
        'reloan_policy_snapshot',
        'previous_obligation_amount',
        'previous_obligation_settlement_method',
        'previous_obligation_settled_at',
        'requirements',
        'release_date',
        'release_reference_number',
        'release_method',
        'actual_released_amount',
        'release_remarks',
        'rejection_reason',
        'cancellation_reason',
        'created_by',
        'draft_current_step',
        'legacy_import_fingerprint',
        'legacy_loan_identity',
        'legacy_source_name',
    ];

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
            'first_due_date' => 'date',
            'maturity_date' => 'date',
            'release_date' => 'date',
            'eligibility' => 'array',
            'eligibility_overridden' => 'boolean',
            'requirements' => 'array',
            'reloan_policy_snapshot' => 'array',
            'previous_obligation_settled_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loanType(): BelongsTo
    {
        return $this->belongsTo(LoanType::class);
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(LoanAmortizationEntry::class)->orderBy('installment_number');
    }

    public function approvalHistory(): HasMany
    {
        return $this->hasMany(LoanApprovalHistory::class)->orderBy('performed_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class, 'loan_application_id');
    }

    public function approvalInstance(): MorphOne
    {
        return $this->morphOne(ApprovalInstance::class, 'subject');
    }

    public function approvalActions(): MorphMany
    {
        return $this->morphMany(ApprovalAction::class, 'subject')->orderBy('acted_at');
    }

    public function previousLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'previous_loan_id');
    }

    public function rootLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'root_loan_id');
    }

    /** Every reloan created directly from this loan (may be more than one if a rejected attempt was retried). */
    public function subsequentReloans(): HasMany
    {
        return $this->hasMany(Loan::class, 'previous_loan_id');
    }

    public function eligibilityExceptions(): HasMany
    {
        return $this->hasMany(LoanEligibilityException::class);
    }
}
