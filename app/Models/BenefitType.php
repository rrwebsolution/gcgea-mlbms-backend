<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BenefitType extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'default_amount',
        'maximum_amount',
        'eligibility_requirements',
        'required_membership_months',
        'frequency_limit',
        'required_documents',
        'approval_required',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'required_membership_months' => 'integer',
            'required_documents' => 'array',
            'approval_required' => 'boolean',
        ];
    }
}
