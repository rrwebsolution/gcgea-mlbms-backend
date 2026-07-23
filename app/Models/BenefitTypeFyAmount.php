<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitTypeFyAmount extends Model
{
    protected $fillable = [
        'benefit_type_id',
        'fiscal_year',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'base_amount' => 'decimal:2',
        ];
    }

    public function benefitType(): BelongsTo
    {
        return $this->belongsTo(BenefitType::class);
    }
}
