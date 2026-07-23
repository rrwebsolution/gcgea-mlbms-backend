<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'proration_basis',
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

    /** Contribution/Pabaon-month proration tiers (Resolution No. 24-2026). Empty for flat-amount benefit types. */
    public function prorationTiers(): HasMany
    {
        return $this->hasMany(BenefitTypeProrationTier::class)->orderBy('min_months');
    }

    /** Fiscal-year-indexed base amounts, for benefit types whose 100% base escalates by year. */
    public function fyAmounts(): HasMany
    {
        return $this->hasMany(BenefitTypeFyAmount::class)->orderBy('fiscal_year');
    }
}
