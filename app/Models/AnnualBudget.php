<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class AnnualBudget extends Model
{
    protected $fillable = [
        'fiscal_year',
        'estimated_revenue',
        'status',
        'notes',
        'prepared_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'estimated_revenue' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AnnualBudgetItem::class)->orderBy('display_order')->orderBy('id');
    }

    public function approvalInstance(): MorphOne
    {
        return $this->morphOne(ApprovalInstance::class, 'subject');
    }

    public function approvalActions(): MorphMany
    {
        return $this->morphMany(ApprovalAction::class, 'subject')->orderBy('acted_at');
    }
}
