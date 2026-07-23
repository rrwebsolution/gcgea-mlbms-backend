<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitTypeProrationTier extends Model
{
    protected $fillable = [
        'benefit_type_id',
        'min_months',
        'max_months',
        'percentage',
    ];

    protected function casts(): array
    {
        return [
            'min_months' => 'integer',
            'max_months' => 'integer',
            'percentage' => 'decimal:2',
        ];
    }

    public function benefitType(): BelongsTo
    {
        return $this->belongsTo(BenefitType::class);
    }
}
