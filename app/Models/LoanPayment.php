<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanPayment extends Model
{
    protected $fillable = [
        'payment_reference_number',
        'member_id',
        'loan_application_id',
        'payment_date',
        'amount_paid',
        'principal_portion',
        'interest_portion',
        'penalty',
        'payment_method',
        'payroll_reference',
        'official_receipt_number',
        'received_by',
        'remarks',
        'status',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_application_id');
    }
}
