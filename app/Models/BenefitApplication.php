<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitApplication extends Model
{
    protected $fillable = [
        'application_number',
        'application_date',
        'member_id',
        'benefit_type_id',
        'requested_amount',
        'approved_amount',
        'reason',
        'incident_date',
        'beneficiary_or_recipient',
        'requirements',
        'status',
        'eligibility',
        'eligibility_result',
        'release_date',
        'rejection_reason',
        'cancellation_reason',
        'remarks',
        'created_by',
        'draft_current_step',
    ];

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
            'incident_date' => 'date',
            'release_date' => 'date',
            'requirements' => 'array',
            'eligibility' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function benefitType(): BelongsTo
    {
        return $this->belongsTo(BenefitType::class);
    }
}
