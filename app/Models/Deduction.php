<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deduction extends Model
{
    protected $fillable = [
        'reference_number',
        'member_id',
        'deduction_type_id',
        'period',
        'amount',
        'payment_date',
        'payroll_reference',
        'remarks',
        'encoded_by',
        'status',
        'void_reason',
        'voided_by',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class);
    }
}
