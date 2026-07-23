<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyLoanImport extends Model
{
    protected $fillable = [
        'batch_id',
        'member_import_row_id',
        'created_member_id',
        'cash_pabaon',
        'cash_pabaon_amount',
        'loan_start',
        'loan_start_date',
        'solidarity_assistance_loan',
        'solidarity_assistance_loan_amount',
        'no_of_months',
        'no_of_months_parsed',
        'monthly_amort',
        'monthly_amort_amount',
        'legacy_notes',
        'review_status',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'loan_start_date' => 'date',
            'cash_pabaon_amount' => 'decimal:2',
            'solidarity_assistance_loan_amount' => 'decimal:2',
            'monthly_amort_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MemberImportBatch::class, 'batch_id');
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(MemberImportRow::class, 'member_import_row_id');
    }

    public function createdMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_member_id');
    }
}
