<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanImportBatch extends Model
{
    protected $fillable = [
        'token',
        'original_filename',
        'balance_period',
        'total_rows',
        'created_count',
        'skipped_count',
        'errors',
        'uploaded_by_user_id',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LoanImportBatchRow::class, 'batch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
