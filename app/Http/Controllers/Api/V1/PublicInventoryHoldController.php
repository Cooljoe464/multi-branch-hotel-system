<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\InventoryHold;
use App\Models\PublicApiQuote;
use App\Models\RoomType;
use App\Services\InventoryHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicInventoryHoldController extends Controller
{
    public function store(Request $request, InventoryHoldService $holds): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('public_api_key');
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $request->validate([
            'quote_token' => 'required|string|max:256',
            'session_id' => 'required|string|max:128',
        ]);

        $quote = PublicApiQuote::query()
            ->where('quote_hash', hash('sha256', $request->string('quote_token')->value()))
            ->where('api_key_id', $key->id)
            ->where('branch_id', $branch->id)
            ->firstOrFail();
        abort_unless($quote->usable(), 422, 'The quote has expired or has already been used.');

        $roomType = RoomType::query()
            ->where('branch_id', $branch->id)
            ->whereKey($quote->room_type_id)
            ->firstOrFail();

        $hold = $holds->create(
            $key,
            $branch,
            $roomType,
            $quote->check_in,
            $quote->check_out,
            $request->string('session_id')->value(),
            (string) $request->ip(),
            $quote,
        );

        return response()->json(['data' => $hold], 201);
    }

    public function destroy(Request $request, InventoryHold $hold, InventoryHoldService $holds): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('public_api_key');
        abort_unless($hold->api_key_id === $key->id && $hold->branch_id === $key->branch_id, 404);

        $released = $holds->release($hold);

        return response()->json(['data' => $released]);
    }
}
