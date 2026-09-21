<?php

namespace App\Http\Controllers;

use App\Models\ChannelMapping;
use App\Models\ChannelProviderModel;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChannelMappingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'channel_provider_id' => 'required|exists:channel_providers,id',
            'room_type_id' => 'required|exists:room_types,id',
            'rate_plan_id' => 'required|exists:rate_plans,id',
            'channel_room_code' => 'required|string|max:64',
            'channel_rate_code' => 'required|string|max:64',
        ]);

        $branchId = (int) $user->branch_id;

        $provider = ChannelProviderModel::findOrFail($request->integer('channel_provider_id'));
        abort_unless($provider->branch_id === $branchId, 403);

        $roomType = RoomType::findOrFail($request->integer('room_type_id'));
        abort_unless($roomType->branch_id === $branchId, 403);

        $plan = RatePlan::findOrFail($request->integer('rate_plan_id'));
        abort_unless($plan->branch_id === $branchId, 403);

        ChannelMapping::updateOrCreate(
            [
                'channel' => $provider->provider,
                'channel_room_code' => $request->string('channel_room_code')->value(),
                'channel_rate_code' => $request->string('channel_rate_code')->value(),
            ],
            [
                'branch_id' => $branchId,
                'channel_provider_id' => $provider->id,
                'room_type_id' => $roomType->id,
                'rate_plan_id' => $plan->id,
            ],
        );

        return $this->flashSuccess('Channel mapping saved.');
    }

    public function destroy(ChannelMapping $mapping): RedirectResponse
    {
        $user = request()->user();
        abort_unless($user !== null, 401);
        abort_unless($mapping->branch_id === (int) $user->branch_id, 403);

        $mapping->delete();

        return $this->flashSuccess('Channel mapping deleted.');
    }
}
