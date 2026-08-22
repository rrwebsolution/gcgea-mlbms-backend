<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitPayment extends Model
{
    protected $fillable = [
        'payment_reference_number',
        'benefit_application_id',
        'member_id',
        'payment_date',
        'amount_paid',
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

    public function benefitApplication(): BelongsTo
    {
        return $this->belongsTo(BenefitApplication::class);
    }
}
