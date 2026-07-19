<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'status',
        'assigned_officer',
        'eligibility',
        'eligibility_overridden',
        'eligibility_override_reason',
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
}
