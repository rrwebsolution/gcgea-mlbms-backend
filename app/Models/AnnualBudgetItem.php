<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnnualBudgetItem extends Model
{
    protected $fillable = [
        'account_title',
        'proposed_amount',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'proposed_amount' => 'decimal:2',
            'display_order' => 'integer',
        ];
    }

    public function annualBudget(): BelongsTo
    {
        return $this->belongsTo(AnnualBudget::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class);
    }
}
