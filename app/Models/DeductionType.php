<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeductionType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'default_amount',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'default_amount' => 'decimal:2',
        ];
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class);
    }
}
