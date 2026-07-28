<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribution extends Model
{
    protected $fillable = [
        'reference_number',
        'member_id',
        'contribution_period',
        'contribution_type',
        'amount',
        'payment_date',
        'payment_method',
        'official_receipt_number',
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

    public function fundAllocations(): HasMany
    {
        return $this->hasMany(ContributionFundAllocation::class);
    }
}
