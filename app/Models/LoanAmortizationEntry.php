<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanAmortizationEntry extends Model
{
    protected $fillable = [
        'loan_id',
        'installment_number',
        'due_date',
        'beginning_balance',
        'principal',
        'interest',
        'penalty',
        'amount_due',
        'amount_paid',
        'remaining_balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
