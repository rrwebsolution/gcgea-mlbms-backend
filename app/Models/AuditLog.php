<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'date_time',
        'user_id',
        'user_name',
        'role_name',
        'module',
        'action',
        'record_reference',
        'old_values',
        'new_values',
        'ip_address',
        'device',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_time' => 'datetime',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
