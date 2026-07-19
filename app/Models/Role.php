<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_system_role',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_system_role' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_code', 'id', 'code');
    }

    /** Users whose primary role this is. */
    public function primaryUsers(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /** Users who carry this role as an additional (non-primary) role. */
    public function additionalUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
    }
}
