<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberImportBatch extends Model
{
    protected $fillable = [
        'token',
        'original_filename',
        'storage_path',
        'source_file_ext',
        'selected_sheet_name',
        'column_mapping',
        'sheet_meta',
        'status',
        'uploaded_by_user_id',
        'committed_by_user_id',
        'committed_at',
        'total_rows',
        'imported_rows',
        'pending_review_rows',
        'skipped_rows',
        'error_rows',
        'legacy_loan_flagged_rows',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'sheet_meta' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(MemberImportRow::class, 'batch_id');
    }

    public function legacyLoanImports(): HasMany
    {
        return $this->hasMany(LegacyLoanImport::class, 'batch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by_user_id');
    }
}
