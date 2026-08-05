<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContributionFund extends Model
{
    protected $fillable = ['fund_name', 'allocation_type', 'allocation_value', 'description', 'is_enabled', 'display_order'];

    protected function casts(): array
    {
        return ['allocation_value' => 'decimal:2', 'is_enabled' => 'boolean', 'display_order' => 'integer'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ContributionFundAllocation::class, 'fund_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FundTransaction::class, 'fund_id');
    }
}
