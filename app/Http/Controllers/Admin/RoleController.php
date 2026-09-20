<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);

        $roles = Role::withCount('users')->get()->map(function (Role $role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users_count,
                'is_system' => $role->name === 'Global Admin',
            ];
        });

        $permissions = Permission::all()->map(function (Permission $permission) {
            $parts = explode('.', $permission->name);

            return [
                'name' => $permission->name,
                'group' => $parts[0],
                'action' => $parts[1] ?? '',
            ];
        })->groupBy('group');

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        /** @var array{name: string, permissions: array<int, string>} $validated */
        $validated = $request->validated();
        $permissions = $validated['permissions'];

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($permissions);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role created successfully.']);

        return redirect()->route('admin.roles.index');
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        if ($role->name === 'Global Admin') {
            return back()->withErrors(['name' => 'The Global Admin role cannot be modified.']);
        }

        /** @var array{name?: string, permissions: array<int, string>} $validated */
        $validated = $request->validated();
        $permissions = $validated['permissions'];

        if (isset($validated['name'])) {
            $role->update(['name' => $validated['name']]);
        }

        $role->syncPermissions($permissions);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role updated successfully.']);

        return redirect()->route('admin.roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === 'Global Admin') {
            return back()->withErrors(['name' => 'The Global Admin role cannot be deleted.']);
        }

        if ($role->users()->count() > 0) {
            return back()->withErrors(['name' => 'Cannot delete a role that has users assigned. Remove all users from this role first.']);
        }

        $role->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role deleted successfully.']);

        return redirect()->route('admin.roles.index');
    }
}
