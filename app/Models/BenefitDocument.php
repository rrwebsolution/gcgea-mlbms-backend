<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitDocument extends Model
{
    protected $fillable = ['requirement_label', 'file_name', 'file_path', 'file_size_bytes', 'uploaded_by', 'uploaded_at'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(BenefitApplication::class, 'benefit_application_id');
    }
}
