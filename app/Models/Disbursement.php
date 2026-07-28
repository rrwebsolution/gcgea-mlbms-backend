<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Disbursement extends Model
{
    protected $fillable = [
        'reference_number', 'annual_budget_id', 'annual_budget_item_id', 'disbursement_date',
        'payee', 'amount', 'payment_method', 'payment_reference', 'remarks', 'status',
        'prepared_by', 'approved_by', 'approved_at', 'paid_by', 'paid_at',
        'rejection_reason', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'disbursement_date' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function annualBudget(): BelongsTo { return $this->belongsTo(AnnualBudget::class); }
    public function budgetItem(): BelongsTo { return $this->belongsTo(AnnualBudgetItem::class, 'annual_budget_item_id'); }
    public function approvalInstance(): MorphOne { return $this->morphOne(ApprovalInstance::class, 'subject'); }
    public function approvalActions(): MorphMany { return $this->morphMany(ApprovalAction::class, 'subject')->orderBy('acted_at'); }
}
