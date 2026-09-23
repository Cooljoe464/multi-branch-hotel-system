<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Jobs\ProcessExcelImportJob;
use App\Models\Branch;
use App\Models\FunctionSpace;
use App\Models\GroupBlock;
use App\Models\RoomType;
use App\Services\GroupBlockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class GroupBlockController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $blocks = GroupBlock::forBranch($branch->id)
            ->withCount('reservations')
            ->withSum('nights as held_nights', 'blocked')
            ->withSum('nights as picked_nights', 'picked_up')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('groups/Index', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'blocks' => $blocks,
            'spaces' => FunctionSpace::forBranch($branch->id)->orderBy('name')->get(),
            'roomTypes' => RoomType::forBranch($branch->id)->active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:32',
            'cutoff_date' => 'required|date',
            'attrition_pct' => 'nullable|integer|min:0|max:100',
            'holds' => 'required|array|min:1|max:50',
            'holds.*.room_type_id' => 'required|exists:room_types,id',
            'holds.*.from' => 'required|date',
            'holds.*.to' => 'required|date|after:holds.*.from',
            'holds.*.blocked' => 'required|integer|min:1|max:500',
        ]);

        if (GroupBlock::forBranch($branch->id)->where('code', $request->string('code')->value())->exists()) {
            return back()->withErrors(['code' => 'A block with this code already exists for this property.']);
        }

        try {
            $block = (new GroupBlockService)->create(
                $branch,
                [
                    'name' => $request->string('name')->value(),
                    'code' => $request->string('code')->value(),
                    'cutoff_date' => $request->string('cutoff_date')->value(),
                    'attrition_pct' => $request->integer('attrition_pct', 0),
                    'status' => GroupBlock::STATUS_DEFINITE,
                ],
                $this->holdsPayload($request),
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['holds' => $e->getMessage()]);
        }

        return redirect()->route('groups.show', ['branch' => $branch->id, 'block' => $block->id])
            ->with('toast', ['type' => 'success', 'message' => "Block {$block->code} created."]);
    }

    public function show(Branch $branch, GroupBlock $block): Response
    {
        $this->ensureBranchAccess($branch);
        abort_unless($block->branch_id === $branch->id, 404);

        $block->load([
            'nights.roomType',
            'reservations' => fn (Builder $q) => $q->orderBy('guest_name'),
            'beos' => fn (Builder $q) => $q->with('functionSpace')->orderBy('event_date'),
            'masterFolio',
        ]);

        return Inertia::render('groups/Show', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'block' => $block,
            'spaces' => FunctionSpace::forBranch($branch->id)->orderBy('name')->get(),
            'roomTypes' => RoomType::forBranch($branch->id)->active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function pickup(Request $request, Branch $branch, GroupBlock $block): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($block->branch_id === $branch->id, 404);

        $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'room_id' => 'nullable|exists:rooms,id',
            'guest_name' => 'required|string|max:255',
            'guest_email' => 'nullable|email|max:255',
            'guest_phone' => 'nullable|string|max:50',
            'adults' => 'required|integer|min:1|max:10',
            'children' => 'required|integer|min:0|max:10',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'room_rate' => 'required|integer|min:0',
        ]);

        $roomType = RoomType::findOrFail($request->integer('room_type_id'));
        abort_unless($roomType->branch_id === $branch->id, 403);

        $nights = max(1, (int) Carbon::parse($request->string('check_in_date')->value())
            ->diffInDays(Carbon::parse($request->string('check_out_date')->value())));

        try {
            $reservation = (new GroupBlockService)->pickup(
                $block,
                $roomType,
                $request->string('check_in_date')->value(),
                $request->string('check_out_date')->value(),
                [
                    'currency_code' => $branch->currency_code,
                    'guest_name' => $request->string('guest_name')->value(),
                    'guest_email' => $request->string('guest_email')->value() ?: null,
                    'guest_phone' => $request->string('guest_phone')->value() ?: null,
                    'adults' => $request->integer('adults'),
                    'children' => $request->integer('children'),
                    'room_rate' => $request->integer('room_rate'),
                    'total_amount' => $request->integer('room_rate') * $nights,
                    'status' => 'confirmed',
                    'source' => 'group_block',
                    'payment_status' => 'pending',
                ],
                $request->filled('room_id') ? $request->integer('room_id') : null,
                $request->header('X-Idempotency-Key'),
                $request->user(),
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['pickup' => $e->getMessage()]);
        }

        return $this->flashSuccess("Picked up {$reservation->confirmation_number} from {$block->code}.");
    }

    public function release(Branch $branch, GroupBlock $block): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($block->branch_id === $branch->id, 404);

        $released = (new GroupBlockService)->release($block, GroupBlock::STATUS_CANCELLED, 'manual');

        return $this->flashSuccess("Released {$released} held nights; block cancelled.");
    }

    public function importRoomingList(Request $request, Branch $branch, GroupBlock $block): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($block->branch_id === $branch->id, 404);

        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');

        if (! $file) {
            return back()->withErrors(['file' => 'File upload failed.']);
        }

        $path = $file->store('imports', 'local');

        if ($path === false) {
            return back()->withErrors(['file' => 'Failed to store uploaded file.']);
        }

        $user = Auth::user();
        abort_unless($user !== null, 401);

        ProcessExcelImportJob::dispatch($path, "rooming:{$block->id}", $branch->id, $user->id);

        return back()->with([
            'flash' => ['type' => 'success', 'message' => 'Rooming list queued for processing.'],
        ]);
    }

    /**
     * @return list<array{room_type_id: int, from: string, to: string, blocked: int}>
     */
    private function holdsPayload(Request $request): array
    {
        $raw = $request->input('holds');
        $holds = [];

        if (is_array($raw)) {
            foreach ($raw as $hold) {
                if (! is_array($hold)) {
                    continue;
                }

                $roomTypeId = $hold['room_type_id'] ?? 0;
                $from = $hold['from'] ?? '';
                $to = $hold['to'] ?? '';
                $blocked = $hold['blocked'] ?? 0;

                $holds[] = [
                    'room_type_id' => is_int($roomTypeId) ? $roomTypeId : 0,
                    'from' => is_string($from) ? $from : '',
                    'to' => is_string($to) ? $to : '',
                    'blocked' => is_int($blocked) ? $blocked : 0,
                ];
            }
        }

        return $holds;
    }
}
