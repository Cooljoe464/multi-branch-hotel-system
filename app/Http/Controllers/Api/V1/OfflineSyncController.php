<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\OfflineCommand;
use App\Services\OfflineOverrideService;
use App\Services\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineSyncController extends Controller
{
    public function push(Request $request, OfflineSyncService $sync): JsonResponse
    {
        $branch = $this->branch($request);
        $request->validate([
            'device_id' => 'required|string|max:128',
            'commands' => 'required|array|max:100',
        ]);

        return response()->json(['data' => $sync->push($branch, $request->string('device_id')->value(), $request->array('commands'))]);
    }

    public function pull(Request $request, OfflineSyncService $sync): JsonResponse
    {
        $branch = $this->branch($request);
        $request->validate(['device_id' => 'required|string|max:128', 'since' => 'nullable|date']);

        return response()->json(['data' => $sync->pull($branch, $request->string('device_id')->value(), $request->string('since')->value() ?: null)]);
    }

    public function issueOverride(Request $request, OfflineOverrideService $overrides): JsonResponse
    {
        $request->validate(['command_id' => 'required|integer|exists:offline_commands,id']);
        $command = OfflineCommand::query()->whereKey($request->integer('command_id'))->where('branch_id', $this->branch($request)->id)->firstOrFail();

        return response()->json(['data' => $overrides->issue($command)]);
    }

    private function branch(Request $request): Branch
    {
        $branch = $request->input('_branch');

        abort_unless($branch instanceof Branch, 403, 'No active branch context.');

        return $branch;
    }
}
