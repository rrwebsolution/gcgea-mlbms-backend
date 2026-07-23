<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['section', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
