<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRecord extends Model
{
    protected $fillable = [
        'name', 'path', 'type', 'size_bytes', 'status', 'created_by',
        'includes_attachments', 'error_message',
    ];

    protected function casts(): array
    {
        return ['includes_attachments' => 'boolean'];
    }
}
