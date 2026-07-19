<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApprovalHistory extends Model
{
    protected $table = 'loan_approval_histories';

    protected $fillable = [
        'loan_id',
        'action',
        'performed_by',
        'performed_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
