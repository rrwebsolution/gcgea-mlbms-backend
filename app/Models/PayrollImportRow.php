<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollImportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_data',
        'matched_member_id',
        'matched_loan_id',
        'match_method',
        'validation_category',
        'validation_reasons',
        'excluded',
        'row_status',
        'created_loan_payment_id',
        'created_contribution_id',
        'created_deduction_id',
        'loan_principal_balance_before',
        'loan_interest_balance_before',
        'loan_status_before',
        'loan_principal_balance_after',
        'loan_interest_balance_after',
        'loan_status_after',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'validation_reasons' => 'array',
            'excluded' => 'boolean',
            'loan_principal_balance_before' => 'decimal:2',
            'loan_interest_balance_before' => 'decimal:2',
            'loan_principal_balance_after' => 'decimal:2',
            'loan_interest_balance_after' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollImportBatch::class, 'batch_id');
    }
}
