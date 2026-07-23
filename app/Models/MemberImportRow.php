<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberImportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_data',
        'validation_category',
        'validation_reasons',
        'duplicate_score',
        'duplicate_candidate_member_ids',
        'resolved_action',
        'resolved_office_id',
        'unresolved_office_text',
        'row_status',
        'created_member_id',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'validation_reasons' => 'array',
            'duplicate_candidate_member_ids' => 'array',
            'duplicate_score' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MemberImportBatch::class, 'batch_id');
    }
}
