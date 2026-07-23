<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollDeductionDetail extends Model
{
    protected $fillable = ['payroll_deduction_header_id', 'deduction_code', 'amount', 'loan_id', 'contribution_id', 'deduction_id', 'loan_payment_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(PayrollDeductionHeader::class, 'payroll_deduction_header_id');
    }
}
