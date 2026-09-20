<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);

        $users = User::with('roles', 'currentBranch', 'branches')->get()->map(function (User $user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_global_admin' => $user->is_global_admin,
                'roles' => $user->getRoleNames(),
                'current_branch' => $user->currentBranch?->only('id', 'name'),
                'branch_ids' => $user->branches->pluck('id'),
                'default_branch_id' => $user->defaultBranch()?->id,
                'last_login_at' => $user->last_login_at?->toISOString(),
            ];
        });

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'roles' => Role::all()->pluck('name', 'id'),
            'branches' => Branch::where('is_active', true)->get()->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
            ]),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, password: string, is_global_admin: bool, roles: array<int, string>, branch_ids: array<int, int>, default_branch_id: int} $validated */
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_global_admin' => $validated['is_global_admin'],
        ]);

        $user->syncRoles($validated['roles']);

        $branchIds = $validated['branch_ids'];
        $defaultBranchId = $validated['default_branch_id'];

        $pivotData = [];
        foreach ($branchIds as $branchId) {
            $pivotData[$branchId] = [
                'is_default' => $branchId === $defaultBranchId,
            ];
        }
        $user->branches()->sync($pivotData);

        $user->update(['branch_id' => $defaultBranchId]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User created successfully.']);

        return redirect()->route('admin.users.index');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        /** @var array{name?: string, email?: string, password?: string, is_global_admin?: bool, roles: array<int, string>, branch_ids: array<int, int>, default_branch_id: int} $validated */
        $validated = $request->validated();

        /** @var array<string, mixed> $data */
        $data = $request->only(['name', 'email', 'is_global_admin']);

        if ($request->filled('password') && isset($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        $user->syncRoles($validated['roles']);

        $branchIds = $validated['branch_ids'];
        $defaultBranchId = $validated['default_branch_id'];

        $pivotData = [];
        foreach ($branchIds as $branchId) {
            $pivotData[$branchId] = [
                'is_default' => $branchId === $defaultBranchId,
            ];
        }
        $user->branches()->sync($pivotData);

        $user->update(['branch_id' => $defaultBranchId]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User updated successfully.']);

        return redirect()->route('admin.users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->withErrors(['name' => 'You cannot delete your own account.']);
        }

        $user->branches()->detach();
        $user->syncRoles([]);
        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User deleted successfully.']);

        return redirect()->route('admin.users.index');
    }
}
