<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Beneficiary extends Model
{
    protected $fillable = [
        'member_id',
        'full_name',
        'relationship',
        'birthdate',
        'contact_number',
        'address',
        'share_percentage',
        'priority_order',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'share_percentage' => 'float',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
