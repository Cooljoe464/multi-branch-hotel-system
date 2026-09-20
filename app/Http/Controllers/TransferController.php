<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\TransferRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $transfers = TransferRequest::forBranch($branchId)
            ->with(['fromBranch', 'toBranch', 'requester'])
            ->when($request->filled('status'), fn ($q) => $q->forStatus($request->string('status')->value()))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $branches = Branch::active()->where('id', '!=', $branchId)->get();

        return Inertia::render('transfers/Index', [
            'transfers' => $transfers,
            'branches' => $branches,
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(TransferRequest $transferRequest): Response
    {
        $user = request()->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($transferRequest->fromBranch);

        return Inertia::render('transfers/Show', [
            'transfer' => $transferRequest->load(['fromBranch', 'toBranch', 'requester']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'to_branch_id' => 'required|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        TransferRequest::create([
            'from_branch_id' => $user->branch_id,
            'to_branch_id' => $request->integer('to_branch_id'),
            'requested_by' => $user->id,
            'items' => $request->input('items'),
            'notes' => $request->string('notes')->value() ?: null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transfer request created.']);

        return redirect()->route('transfers.index');
    }

    public function approve(TransferRequest $transferRequest): RedirectResponse
    {
        $this->ensureBranchAccess($transferRequest->toBranch);

        $transferRequest->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return $this->flashSuccess('Transfer approved.');
    }

    public function ship(TransferRequest $transferRequest): RedirectResponse
    {
        $this->ensureBranchAccess($transferRequest->fromBranch);

        $errors = [];

        DB::transaction(function () use ($transferRequest, &$errors) {
            foreach ($transferRequest->items as $item) {
                $name = $item['name'];
                $quantity = (float) $item['quantity'];

                $inventoryItem = InventoryItem::where('branch_id', $transferRequest->from_branch_id)
                    ->where('name', $name)
                    ->first();

                if (! $inventoryItem) {
                    $errors[] = "Item '{$name}' not found at source branch.";

                    continue;
                }

                if ($inventoryItem->current_quantity < $quantity) {
                    $errors[] = "Insufficient stock for '{$name}': have {$inventoryItem->current_quantity}, need {$quantity}.";

                    continue;
                }

                $locked = InventoryItem::where('id', $inventoryItem->id)->lockForUpdate()->first();
                if ($locked) {
                    $locked->update(['current_quantity' => $locked->current_quantity - $quantity]);

                    InventoryTransaction::create([
                        'branch_id' => $transferRequest->from_branch_id,
                        'inventory_item_id' => $locked->id,
                        'type' => 'deduction',
                        'quantity' => $quantity,
                        'notes' => "Transfer #{$transferRequest->id} shipped to branch {$transferRequest->to_branch_id}",
                    ]);
                }
            }
        });

        $transferRequest->update([
            'status' => 'in_transit',
            'shipped_at' => now(),
        ]);

        $message = 'Transfer shipped. Inventory deducted from source branch.';
        if (! empty($errors)) {
            $message .= ' Warnings: '.implode(' ', $errors);
        }

        return $this->flashSuccess($message);
    }

    public function receive(TransferRequest $transferRequest): RedirectResponse
    {
        $this->ensureBranchAccess($transferRequest->toBranch);

        $errors = [];

        DB::transaction(function () use ($transferRequest, &$errors) {
            foreach ($transferRequest->items as $item) {
                $name = $item['name'];
                $quantity = (float) $item['quantity'];

                $inventoryItem = InventoryItem::where('branch_id', $transferRequest->to_branch_id)
                    ->where('name', $name)
                    ->first();

                if (! $inventoryItem) {
                    $errors[] = "Item '{$name}' not found at destination branch. Manual restock required.";

                    continue;
                }

                $locked = InventoryItem::where('id', $inventoryItem->id)->lockForUpdate()->first();
                if ($locked) {
                    $locked->update(['current_quantity' => $locked->current_quantity + $quantity]);

                    InventoryTransaction::create([
                        'branch_id' => $transferRequest->to_branch_id,
                        'inventory_item_id' => $locked->id,
                        'type' => 'restock',
                        'quantity' => $quantity,
                        'notes' => "Transfer #{$transferRequest->id} received from branch {$transferRequest->from_branch_id}",
                    ]);
                }
            }
        });

        $transferRequest->update([
            'status' => 'received',
            'received_at' => now(),
        ]);

        $message = 'Transfer received. Inventory restocked at destination branch.';
        if (! empty($errors)) {
            $message .= ' Warnings: '.implode(' ', $errors);
        }

        return $this->flashSuccess($message);
    }
}
