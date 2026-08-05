<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundTransaction extends Model
{
    protected $fillable = ['fund_id', 'fund_name_snapshot', 'transaction_type', 'amount', 'source_type', 'source_id', 'reference_number', 'description', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(ContributionFund::class, 'fund_id');
    }
}
