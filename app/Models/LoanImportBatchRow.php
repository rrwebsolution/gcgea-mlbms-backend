<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanImportBatchRow extends Model
{
    protected $fillable = [
        'batch_id',
        'sheet',
        'row_number',
        'source_name',
        'member_id',
        'loan_id',
        'principal',
        'interest',
        'principal_balance',
        'interest_balance',
        'status',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'principal_balance' => 'decimal:2',
            'interest_balance' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LoanImportBatch::class, 'batch_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
