<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'username',
        'email',
        'contact_number',
        'password',
        'role_id',
        'require_password_change',
        'remarks',
        'status',
        'last_login_at',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'require_password_change' => 'boolean',
        ];
    }

    /** The user's primary role. */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** The office this user belongs to — used by the "office" approver-assignment type. */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** Roles stacked on top of the primary role. */
    public function additionalRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    /** Permission codes directly granted to this user, bypassing role membership. */
    public function allowedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user', 'user_id', 'permission_code', 'id', 'code')
            ->wherePivot('type', 'allow');
    }

    /** Permission codes directly revoked from this user, overriding any role grant. */
    public function deniedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user', 'user_id', 'permission_code', 'id', 'code')
            ->wherePivot('type', 'deny');
    }

    public function loginHistory(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * All roles this user carries — primary plus additional.
     *
     * @return Collection<int, Role>
     */
    public function allRoles(): Collection
    {
        return $this->additionalRoles->when(
            $this->role,
            fn ($roles) => $roles->push($this->role)
        )->unique('id');
    }

    /**
     * Effective permission precedence (highest wins):
     *   1. Direct deny  -> always excluded, regardless of any role grant or direct allow
     *   2. Direct allow -> included, when no direct deny exists
     *   3. Role grant   -> included, when no direct deny exists (primary or additional role)
     *   4. Nothing      -> excluded
     *
     * Mirrors the frontend's computeEffectivePermissionCodes (src/utils/effective-permissions.ts).
     *
     * @return array<int, string>
     */
    public function effectivePermissionCodes(): array
    {
        $this->loadMissing(['role.permissions', 'additionalRoles.permissions', 'allowedPermissions', 'deniedPermissions']);

        $roleGranted = $this->allRoles()->flatMap(fn (Role $role) => $role->permissions->pluck('code'));
        $denied = $this->deniedPermissions->pluck('code');
        $allowed = $this->allowedPermissions->pluck('code');

        return $roleGranted->merge($allowed)
            ->unique()
            ->diff($denied)
            ->values()
            ->all();
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->effectivePermissionCodes(), true);
    }
}
