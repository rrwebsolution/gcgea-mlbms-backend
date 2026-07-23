<?php

namespace App\Http\Controllers;

use App\Http\Requests\Role\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiPagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    private const WITH = ['permissions'];

    private const WITH_COUNT = ['primaryUsers', 'additionalUsers'];

    public function index(Request $request)
    {
        if (! $request->user()->hasPermission('roles.view')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $query = Role::with(self::WITH)->withCount(self::WITH_COUNT);
        $this->applyFilters($query, $request);

        $sortBy = $request->string('sortBy')->toString();
        $query->orderBy($sortBy ?: 'created_at', $sortBy ? ($request->string('sortDir')->toString() ?: 'asc') : 'desc');

        $paginator = $query->paginate($request->integer('perPage', 10), page: $request->integer('page', 1));

        return response()->json(ApiPagination::make($paginator, RoleResource::class));
    }

    /**
     * Full, unpaginated role list — used both by admin Roles pages and by the
     * Approval Workflow admin's approver-by-role picker, so it is intentionally
     * not permission-gated the same way index()/show() are.
     */
    public function all()
    {
        return RoleResource::collection(
            Role::with(self::WITH)->withCount(self::WITH_COUNT)->orderBy('name')->get()
        );
    }

    public function show(Request $request, Role $role)
    {
        if (! $request->user()->hasPermission('roles.view')) {
            abort(403, "You don't have permission to perform this action.");
        }

        return new RoleResource($role->load(self::WITH)->loadCount(self::WITH_COUNT));
    }

    public function store(RoleRequest $request)
    {
        if (! $request->user()->hasPermission('roles.create')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $role = DB::transaction(function () use ($request) {
            $role = Role::create([
                'name' => $request->string('name')->toString(),
                'code' => $request->string('code')->toString(),
                'description' => $request->input('description'),
                'is_system_role' => false,
                'status' => $request->string('status')->toString(),
            ]);
            $role->permissions()->sync($request->input('permissions', []));

            return $role;
        });

        return new RoleResource($role->load(self::WITH)->loadCount(self::WITH_COUNT));
    }

    public function update(RoleRequest $request, Role $role)
    {
        if (! $request->user()->hasPermission('roles.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        DB::transaction(function () use ($request, $role) {
            $role->update([
                'name' => $request->string('name')->toString(),
                'code' => $request->string('code')->toString(),
                'description' => $request->input('description'),
                'status' => $request->string('status')->toString(),
            ]);

            $role->permissions()->sync($this->permissionsFor($role, $request->input('permissions', [])));
        });

        return new RoleResource($role->load(self::WITH)->loadCount(self::WITH_COUNT));
    }

    public function updatePermissions(Request $request, Role $role)
    {
        if (! $request->user()->hasPermission('roles.assign_permissions')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,code'],
        ]);

        $role->permissions()->sync($this->permissionsFor($role, $request->input('permissions', [])));

        return new RoleResource($role->load(self::WITH)->loadCount(self::WITH_COUNT));
    }

    public function duplicate(Request $request, Role $role)
    {
        if (! $request->user()->hasPermission('roles.duplicate')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $newRole = DB::transaction(function () use ($role) {
            $name = "{$role->name} (Copy)";
            $suffix = 2;
            while (Role::where('name', $name)->exists()) {
                $name = "{$role->name} (Copy {$suffix})";
                $suffix++;
            }

            $new = Role::create([
                'name' => $name,
                'code' => $this->uniqueCodeFrom($role->code),
                'description' => $role->description,
                'is_system_role' => false,
                'status' => 'Active',
            ]);
            $new->permissions()->sync($role->permissions->pluck('code'));

            return $new;
        });

        return new RoleResource($newRole->load(self::WITH)->loadCount(self::WITH_COUNT));
    }

    public function toggleStatus(Request $request, Role $role)
    {
        if (! $request->user()->hasPermission('roles.update')) {
            abort(403, "You don't have permission to perform this action.");
        }

        $role->update(['status' => $role->status === 'Active' ? 'Inactive' : 'Active']);

        return new RoleResource($role->load(self::WITH)->loadCount(self::WITH_COUNT));
    }

    public function destroy(Request $request, Role $role)
    {
        if (! $request->user()->hasPermission('roles.delete')) {
            abort(403, "You don't have permission to perform this action.");
        }
        if ($role->is_system_role) {
            abort(403, 'System roles cannot be deleted.');
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /**
     * The Super Administrator role must always retain full access, regardless
     * of what a form submission tries to set.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    private function permissionsFor(Role $role, array $requested): array
    {
        return $role->code === 'super_administrator' ? Permission::pluck('code')->all() : $requested;
    }

    private function uniqueCodeFrom(string $baseCode): string
    {
        $code = $baseCode.'_copy';
        $suffix = 2;
        while (Role::where('code', $code)->exists()) {
            $code = $baseCode.'_copy'.$suffix;
            $suffix++;
        }

        return $code;
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($roleType = $request->string('roleType')->toString()) {
            $query->where('is_system_role', $roleType === 'System');
        }
    }
}
