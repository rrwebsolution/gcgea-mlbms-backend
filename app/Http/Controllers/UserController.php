<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UserRequest;
use App\Http\Resources\LoginHistoryResource;
use App\Http\Resources\SystemUserResource;
use App\Http\Resources\UserResource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    private const WITH = ['role', 'additionalRoles', 'allowedPermissions', 'deniedPermissions', 'member'];

    public function index(Request $request)
    {
        if (! $request->user()->hasPermission('users.view')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $query = User::with(self::WITH);
        $this->applyFilters($query, $request);
        $query->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, SystemUserResource::class));
    }

    /**
     * Full, unpaginated user list — used both by admin Users pages and by the
     * Approval Workflow admin's approver-by-user picker, so it is intentionally
     * not permission-gated the same way index()/show() are. Defaults to Active only
     * (pickers should never offer a deactivated/disabled user as an approver);
     * ?includeInactive=1 lifts that for the Users management page itself, which
     * needs to list every status so admins can find and reactivate accounts.
     */
    public function all(Request $request)
    {
        $query = User::with(self::WITH)->orderBy('full_name');

        if (! $request->boolean('includeInactive')) {
            $query->where('status', 'Active');
        }

        return SystemUserResource::collection($query->get());
    }

    public function show(Request $request, User $user)
    {
        if (! $request->user()->hasPermission('users.view')) {
            abort(403, "You don't have permission to perform this action.");
        }

        return new SystemUserResource($user->load(self::WITH));
    }

    public function store(UserRequest $request)
    {
        if (! $request->user()->hasPermission('users.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'full_name' => $request->string('fullName')->toString(),
                'username' => $request->string('username')->toString(),
                'email' => $request->string('email')->toString(),
                'contact_number' => $request->input('contactNumber'),
                'password' => Hash::make($request->string('password')->toString()),
                'role_id' => $request->input('roleId'),
                'member_id' => $request->input('memberId'),
                'require_password_change' => $request->boolean('requirePasswordChange')
                    || SystemSetting::security()['requirePasswordChangeOnFirstLogin'],
                'remarks' => $request->input('remarks'),
                'status' => $request->string('status')->toString(),
                'email_verified_at' => now(),
            ]);

            $user->additionalRoles()->sync($request->input('additionalRoleIds', []));
            $this->syncPermissionOverrides($user, $request);

            return $user;
        });

        return new SystemUserResource($user->load(self::WITH));
    }

    public function update(UserRequest $request, User $user)
    {
        if (! $request->user()->hasPermission('users.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        DB::transaction(function () use ($request, $user) {
            $user->update([
                'full_name' => $request->string('fullName')->toString(),
                'username' => $request->string('username')->toString(),
                'email' => $request->string('email')->toString(),
                'contact_number' => $request->input('contactNumber'),
                'role_id' => $request->input('roleId'),
                'member_id' => $request->input('memberId'),
                'require_password_change' => $request->boolean('requirePasswordChange'),
                'remarks' => $request->input('remarks'),
                'status' => $request->string('status')->toString(),
                ...($request->filled('password') ? ['password' => Hash::make($request->string('password')->toString())] : []),
            ]);

            $user->additionalRoles()->sync($request->input('additionalRoleIds', []));
            $this->syncPermissionOverrides($user, $request);
        });

        return new SystemUserResource($user->load(self::WITH));
    }

    public function updatePermissions(Request $request, User $user)
    {
        if (! $request->user()->hasPermission('users.assign_permissions')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $request->validate([
            'allowedPermissions' => ['array'],
            'allowedPermissions.*' => ['string', 'exists:permissions,code'],
            'deniedPermissions' => ['array'],
            'deniedPermissions.*' => ['string', 'exists:permissions,code'],
        ]);

        $this->syncPermissionOverrides($user, $request);

        return new SystemUserResource($user->load(self::WITH));
    }

    public function toggleStatus(Request $request, User $user)
    {
        $code = $user->status === 'Active' ? 'users.deactivate' : 'users.activate';
        if (! $request->user()->hasPermission($code)) {
            abort(403, "You don't have permission to perform this action.");
        }

        $user->update(['status' => $user->status === 'Active' ? 'Inactive' : 'Active']);

        return new SystemUserResource($user->load(self::WITH));
    }

    public function resetPassword(Request $request, User $user)
    {
        if (! $request->user()->hasPermission('users.reset_password')) {
            abort(403, "You don't have permission to perform this action.");
        }

        Password::sendResetLink(['email' => $user->email]);

        return response()->json(['message' => 'Password reset link sent.']);
    }

    public function loginHistory(Request $request, User $user)
    {
        if (! $request->user()->hasPermission('users.view_login_history')) {
            abort(403, "You don't have permission to perform this action.");
        }

        return LoginHistoryResource::collection($user->loginHistory()->orderByDesc('login_at')->get());
    }

    public function updateMyAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        if ($user->avatar_url) {
            Storage::disk('public')->delete($this->storagePathFromUrl($user->avatar_url));
        }

        $path = $request->file('avatar')->store("user-avatars/{$user->id}", 'public');
        $user->update(['avatar_url' => Storage::disk('public')->url($path)]);

        return new UserResource($user->fresh()->load('role', 'additionalRoles', 'allowedPermissions', 'deniedPermissions'));
    }

    public function removeMyAvatar(Request $request)
    {
        $user = $request->user();
        if ($user->avatar_url) {
            Storage::disk('public')->delete($this->storagePathFromUrl($user->avatar_url));
            $user->update(['avatar_url' => null]);
        }

        return new UserResource($user->fresh()->load('role', 'additionalRoles', 'allowedPermissions', 'deniedPermissions'));
    }

    private function storagePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return ltrim(preg_replace('#^/storage/#', '', $path), '/');
    }

    /**
     * Full replace-sync of a user's direct allow/deny permission overrides.
     */
    private function syncPermissionOverrides(User $user, Request $request): void
    {
        DB::table('permission_user')->where('user_id', $user->id)->delete();

        $rows = collect($request->input('allowedPermissions', []))
            ->map(fn ($code) => ['user_id' => $user->id, 'permission_code' => $code, 'type' => 'allow'])
            ->concat(
                collect($request->input('deniedPermissions', []))
                    ->map(fn ($code) => ['user_id' => $user->id, 'permission_code' => $code, 'type' => 'deny'])
            );

        if ($rows->isNotEmpty()) {
            DB::table('permission_user')->insert($rows->all());
        }
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                    ->orWhere('username', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($roleId = $request->string('roleId')->toString()) {
            $query->where(function ($q) use ($roleId) {
                $q->where('role_id', $roleId)
                    ->orWhereHas('additionalRoles', fn ($rq) => $rq->where('roles.id', $roleId));
            });
        }
    }
}
