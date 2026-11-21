<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\OfflineCommand;
use App\Services\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfflineConflictController extends Controller
{
    /**
     * Pending offline room conflicts for the active property, newest first.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch($request);

        $conflicts = OfflineCommand::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'conflict')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $conflicts->map(fn (OfflineCommand $command): array => $this->payload($command))->values()]);
    }

    public function page(Request $request): Response
    {
        $branch = $this->branch($request);

        $conflicts = OfflineCommand::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'conflict')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('offline/Conflicts', [
            'branch' => $branch->only(['id', 'name']),
            'conflicts' => $conflicts->map(fn (OfflineCommand $command): array => $this->payload($command))->values(),
        ]);
    }

    /**
     * Supervisor resolves a recorded room conflict: optionally reassign the
     * guest to another clean room, or discard the offline assignment.
     */
    public function resolve(Request $request, OfflineCommand $command, OfflineSyncService $sync): JsonResponse
    {
        $branch = $this->branch($request);
        $offline = OfflineCommand::query()
            ->whereKey($command->id)
            ->where('branch_id', $branch->id)
            ->firstOrFail();

        if ($offline->status !== 'conflict') {
            return response()->json(['data' => ['status' => $offline->status]], 409);
        }

        $offline->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()?->id,
            'resolved_at' => now(),
            'resolution_note' => is_string($request->input('resolution_note')) && $request->input('resolution_note') !== ''
                ? $request->input('resolution_note')
                : 'Supervisor resolved the offline conflict.',
        ]);

        $roomId = filter_var($request->input('room_id') ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($roomId !== false) {
            // Re-run the conflict-checked provisional application against the
            // reassigned room so the guest actually receives the room change.
            $offline->update(['payload' => array_merge($offline->payload, ['room_id' => $roomId])]);
            $sync->applyProvisionalCheckIn($offline, $branch, $offline->payload);
        }

        return response()->json(['data' => ['status' => $offline->fresh()->status]]);
    }

    public function discard(Request $request, OfflineCommand $command): JsonResponse
    {
        $branch = $this->branch($request);
        $offline = OfflineCommand::query()
            ->whereKey($command->id)
            ->where('branch_id', $branch->id)
            ->firstOrFail();

        if ($offline->status !== 'conflict') {
            return response()->json(['data' => ['status' => $offline->status]], 409);
        }

        $offline->update([
            'status' => 'discarded',
            'resolved_by' => $request->user()?->id,
            'resolved_at' => now(),
            'resolution_note' => is_string($request->input('resolution_note')) && $request->input('resolution_note') !== ''
                ? $request->input('resolution_note')
                : 'Supervisor discarded the offline conflict.',
        ]);

        return response()->json(['data' => ['status' => $offline->fresh()->status]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OfflineCommand $command): array
    {
        $payload = $command->payload;

        return [
            'id' => $command->id,
            'command_uuid' => $command->command_uuid,
            'command_type' => $command->command_type,
            'device_id' => $command->device_id,
            'room_id' => $payload['room_id'] ?? null,
            'room_type_id' => $payload['room_type_id'] ?? null,
            'guest_name' => $payload['guest_name'] ?? null,
            'check_in_date' => $payload['check_in_date'] ?? null,
            'check_out_date' => $payload['check_out_date'] ?? null,
            'status' => $command->status,
            'reason' => $command->reason,
            'created_at' => $command->created_at?->toIso8601String(),
            'updated_at' => $command->updated_at?->toIso8601String(),
            'resolved_by' => $command->resolved_by,
            'resolved_at' => $command->resolved_at?->toIso8601String(),
            'resolution_note' => $command->resolution_note,
        ];
    }

    private function branch(Request $request): Branch
    {
        $branch = $request->input('_branch');

        abort_unless($branch instanceof Branch, 403, 'No active branch context.');

        return $branch;
    }
}
