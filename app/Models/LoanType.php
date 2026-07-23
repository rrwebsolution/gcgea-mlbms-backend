<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanType extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'min_amount',
        'max_amount',
        'default_interest_rate',
        'interest_method',
        'processing_fee',
        'service_charge_percent',
        'max_term_months',
        'required_membership_months',
        'required_contribution_months',
        'allow_existing_active_loan',
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
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'default_interest_rate' => 'decimal:2',
            'processing_fee' => 'decimal:2',
            'service_charge_percent' => 'decimal:2',
            'max_term_months' => 'integer',
            'required_membership_months' => 'integer',
            'required_contribution_months' => 'integer',
            'allow_existing_active_loan' => 'boolean',
        ];
    }

    /** Net-take-home-pay income brackets (Resolution No. 24-2026). Empty for flat-amount loan types. */
    public function incomeBrackets(): HasMany
    {
        return $this->hasMany(LoanTypeIncomeBracket::class)->orderBy('min_net_pay');
    }
}
