<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(OfficeAlias::class);
    }
}
