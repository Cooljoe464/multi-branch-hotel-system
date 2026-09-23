<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\BanquetEventOrder;
use App\Models\Branch;
use App\Models\FunctionSpace;
use App\Models\GroupBlock;
use App\Services\GroupBlockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BeoController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch, GroupBlock $block): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($block->branch_id === $branch->id, 404);

        $request->validate([
            'function_space_id' => 'required|exists:function_spaces,id',
            'event_date' => 'required|date',
            'schedule' => 'nullable|array',
            'agreed_total_minor' => 'required|integer|min:0',
        ]);

        $space = FunctionSpace::findOrFail($request->integer('function_space_id'));
        abort_unless($space->branch_id === $branch->id, 403);

        $schedule = $request->input('schedule');

        BanquetEventOrder::create([
            'branch_id' => $branch->id,
            'group_block_id' => $block->id,
            'function_space_id' => $space->id,
            'event_date' => $request->string('event_date')->value(),
            'schedule' => is_array($schedule) ? $schedule : null,
            'agreed_total_minor' => $request->integer('agreed_total_minor'),
            'status' => BanquetEventOrder::STATUS_DRAFT,
        ]);

        return $this->flashSuccess('BEO drafted.');
    }

    public function update(Request $request, Branch $branch, BanquetEventOrder $beo): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($beo->branch_id === $branch->id, 404);

        if ($beo->status === BanquetEventOrder::STATUS_POSTED) {
            return back()->withErrors(['beo' => 'Posted BEOs are immutable; void the folio line instead.']);
        }

        $request->validate([
            'event_date' => 'sometimes|date',
            'schedule' => 'nullable|array',
            'agreed_total_minor' => 'sometimes|integer|min:0',
            'status' => 'sometimes|string|in:draft,confirmed,cancelled',
        ]);

        $schedule = $request->input('schedule');

        $beo->update(array_filter([
            'event_date' => $request->input('event_date'),
            'schedule' => $schedule === null ? null : (is_array($schedule) ? $schedule : []),
            'agreed_total_minor' => $request->input('agreed_total_minor'),
            'status' => $request->input('status'),
        ], fn ($value) => $value !== null));

        return $this->flashSuccess('BEO updated.');
    }

    public function post(Request $request, Branch $branch, BanquetEventOrder $beo): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($beo->branch_id === $branch->id, 404);

        try {
            $charge = (new GroupBlockService)->postBeo($beo, $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['beo' => $e->getMessage()]);
        }

        if ($charge === null) {
            return back()->withErrors(['beo' => 'BEO is posted but no folio line was found.']);
        }

        return $this->flashSuccess("BEO posted to the master folio ({$charge->amount} minor).");
    }
}
