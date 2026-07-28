<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollDeductionHeader extends Model
{
    protected $fillable = ['payroll_reference', 'payroll_period', 'payroll_date', 'office_id', 'member_id', 'remarks', 'loanable_amount', 'outstanding_interest', 'outstanding_balance', 'total_deduction', 'status', 'created_by_user_id', 'posted_by_user_id', 'posted_at'];

    protected function casts(): array
    {
        return [
            'payroll_date' => 'date',
            'loanable_amount' => 'decimal:2',
            'outstanding_interest' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'total_deduction' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(PayrollDeductionDetail::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
