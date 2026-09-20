<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MenuItemController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $menuItems = MenuItem::forBranch($branchId)
            ->when($request->filled('category'), fn ($q) => $q->forCategory($request->string('category')->value()))
            ->when($request->filled('available'), fn ($q) => $q->where('is_available', $request->boolean('available')))
            ->orderBy('category')
            ->orderBy('sort_order')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('menu-items/Index', [
            'menuItems' => $menuItems,
            'filters' => $request->only(['category', 'available']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'category' => 'required|string|in:food,drink,laundry,service',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|integer|min:0',
            'dietary_flags' => 'nullable|array',
            'sort_order' => 'integer|min:0',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        MenuItem::create([
            'branch_id' => $user->branch_id,
            'currency_code' => $user->currentBranch->currency_code,
            'category' => $request->string('category')->value(),
            'name' => $request->string('name')->value(),
            'description' => $request->string('description')->value() ?: null,
            'price' => $request->integer('price'),
            'dietary_flags' => $request->input('dietary_flags'),
            'sort_order' => $request->integer('sort_order', 0),
            'is_available' => true,
            'is_active' => true,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Menu item created.']);

        return redirect()->route('menu-items.index');
    }

    public function update(Request $request, MenuItem $menuItem): RedirectResponse
    {
        $this->ensureBranchAccess($menuItem->branch);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'sometimes|integer|min:0',
            'is_available' => 'boolean',
            'is_active' => 'boolean',
            'dietary_flags' => 'nullable|array',
            'sort_order' => 'integer|min:0',
        ]);

        $data = [];
        if ($request->has('name')) {
            $data['name'] = $request->string('name')->value();
        }
        if ($request->has('description')) {
            $data['description'] = $request->string('description')->value() ?: null;
        }
        if ($request->has('price')) {
            $data['price'] = $request->integer('price');
        }
        if ($request->has('is_available')) {
            $data['is_available'] = $request->boolean('is_available');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        if ($request->has('dietary_flags')) {
            $data['dietary_flags'] = $request->input('dietary_flags');
        }
        if ($request->has('sort_order')) {
            $data['sort_order'] = $request->integer('sort_order');
        }

        $menuItem->update($data);

        return $this->flashSuccess('Menu item updated.');
    }

    public function destroy(MenuItem $menuItem): RedirectResponse
    {
        $this->ensureBranchAccess($menuItem->branch);

        $menuItem->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Menu item deleted.']);

        return redirect()->route('menu-items.index');
    }
}
