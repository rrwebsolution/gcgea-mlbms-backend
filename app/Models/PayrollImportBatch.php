<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollImportBatch extends Model
{
    protected $fillable = [
        'token',
        'original_filename',
        'storage_path',
        'payroll_period',
        'payroll_reference',
        'column_mapping',
        'sheet_meta',
        'status',
        'uploaded_by_user_id',
        'committed_by_user_id',
        'committed_at',
        'rolled_back_by_user_id',
        'rolled_back_at',
        'rollback_reason',
        'total_rows',
        'imported_rows',
        'skipped_rows',
        'error_rows',
        'total_loan_payments',
        'total_contributions',
        'total_deductions',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'sheet_meta' => 'array',
            'committed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'total_loan_payments' => 'decimal:2',
            'total_contributions' => 'decimal:2',
            'total_deductions' => 'decimal:2',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PayrollImportRow::class, 'batch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by_user_id');
    }

    public function rolledBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by_user_id');
    }
}
