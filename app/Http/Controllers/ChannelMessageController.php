<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Jobs\PushAriJob;
use App\Models\ChannelMapping;
use App\Models\ChannelMessage;
use App\Models\ChannelProviderModel;
use App\Models\ChannelRate;
use App\Models\ChannelReconciliationRun;
use App\Services\ChannelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChannelMessageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $messages = ChannelMessage::forBranch($branchId)
            ->orderByRaw("CASE WHEN status = 'failed' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        $providers = ChannelProviderModel::where('branch_id', $branchId)->get();

        $mappings = ChannelMapping::forBranch($branchId)
            ->with(['channelProvider', 'roomType', 'ratePlan'])
            ->orderBy('channel')
            ->get();

        $runs = ChannelReconciliationRun::forBranch($branchId)
            ->with('channelProvider')
            ->orderByDesc('stay_date')
            ->limit(60)
            ->get();

        $mappedKeys = $mappings
            ->map(fn (ChannelMapping $m) => $m->channel_provider_id.'.'.$m->room_type_id.'.'.$m->rate_plan_id)
            ->all();

        $unmappedRates = ChannelRate::whereHas('channelProvider', fn ($q) => $q->where('branch_id', $branchId))
            ->with(['channelProvider', 'roomType', 'ratePlan'])
            ->get()
            ->reject(fn (ChannelRate $rate) => in_array(
                $rate->channel_provider_id.'.'.$rate->room_type_id.'.'.$rate->rate_plan_id,
                $mappedKeys,
                true,
            ))
            ->values();

        return Inertia::render('channels/Reliability', [
            'messages' => $messages,
            'providers' => $providers,
            'mappings' => $mappings,
            'runs' => $runs,
            'unmappedRates' => $unmappedRates,
        ]);
    }

    public function replay(ChannelMessage $message): RedirectResponse
    {
        $user = request()->user();
        abort_unless($user !== null, 401);
        abort_unless($message->branch_id === (int) $user->branch_id, 403);

        try {
            $requeued = (new ChannelService)->replay($message);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        PushAriJob::dispatch($requeued->id);

        return $this->flashSuccess('Message requeued for replay.');
    }

    public function push(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'channel_provider_id' => 'required|exists:channel_providers,id',
            'from' => 'required|date',
            'to' => 'required|date|after:from',
        ]);

        $provider = ChannelProviderModel::findOrFail($request->integer('channel_provider_id'));
        abort_unless($provider->branch_id === (int) $user->branch_id, 403);

        $messages = (new ChannelService)->queueAriForProvider(
            $provider,
            $request->string('from')->value(),
            $request->string('to')->value(),
        );

        foreach ($messages as $message) {
            PushAriJob::dispatch($message->id);
        }

        return $this->flashSuccess(count($messages).' ARI messages queued.');
    }
}
