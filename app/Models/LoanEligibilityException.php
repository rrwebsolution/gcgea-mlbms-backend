<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanEligibilityException extends Model
{
    protected $fillable = [
        'loan_id',
        'exception_type',
        'reason',
        'board_resolution_reference',
        'board_resolution_document_path',
        'granted_by',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
