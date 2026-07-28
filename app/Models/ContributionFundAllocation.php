<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionFundAllocation extends Model
{
    protected $fillable = ['contribution_id', 'fund_id', 'fund_name_snapshot', 'allocated_amount'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(ContributionFund::class, 'fund_id');
    }
}
