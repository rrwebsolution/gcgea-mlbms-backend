<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanTypeIncomeBracket extends Model
{
    protected $fillable = [
        'loan_type_id',
        'min_net_pay',
        'max_net_pay',
        'loanable_amount',
    ];

    protected function casts(): array
    {
        return [
            'min_net_pay' => 'decimal:2',
            'max_net_pay' => 'decimal:2',
            'loanable_amount' => 'decimal:2',
        ];
    }

    public function loanType(): BelongsTo
    {
        return $this->belongsTo(LoanType::class);
    }
}
