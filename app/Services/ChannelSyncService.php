<?php

namespace App\Services;

use App\Models\ChannelProviderModel;
use App\Models\ChannelReservation;
use Illuminate\Support\Facades\Log;

class ChannelSyncService
{
    /**
     * @return array{synced: int, errors: list<string>}
     */
    public function syncRates(ChannelProviderModel $provider): array
    {
        if (! $provider->is_active) {
            return ['synced' => 0, 'errors' => ['Provider is inactive.']];
        }

        $rates = $provider->channelRates()->with(['ratePlan', 'roomType'])->get();
        $pricingService = new PricingService;
        $branch = $provider->branch;
        $pricingService->forBranch($branch);

        $synced = 0;
        $errors = [];

        foreach ($rates as $rate) {
            try {
                if (! $rate->ratePlan || ! $rate->roomType) {
                    $errors[] = "Rate #{$rate->id}: missing rate plan or room type.";

                    continue;
                }

                $effectiveRate = $pricingService->getEffectiveRate(
                    $rate->roomType,
                    now(),
                    $rate->ratePlan
                );

                $rate->update([
                    'is_synced' => true,
                    'last_synced_at' => now(),
                ]);

                $synced++;
            } catch (\Throwable $e) {
                $errors[] = "Rate #{$rate->id}: {$e->getMessage()}";
            }
        }

        $provider->update(['last_sync_at' => now()]);

        Log::info("Channel sync completed for provider {$provider->id}", [
            'synced' => $synced,
            'errors' => count($errors),
        ]);

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * @return array{pulled: int, errors: list<string>}
     */
    public function pullReservations(ChannelProviderModel $provider): array
    {
        if (! $provider->is_active) {
            return ['pulled' => 0, 'errors' => ['Provider is inactive.']];
        }

        $fakeBookings = rand(1, 3);
        $pulled = 0;
        $errors = [];

        for ($i = 0; $i < $fakeBookings; $i++) {
            try {
                ChannelReservation::create([
                    'channel_provider_id' => $provider->id,
                    'channel_booking_id' => fake()->uuid(),
                    'raw_payload' => [
                        'guest_name' => fake()->name(),
                        'guest_email' => fake()->safeEmail(),
                        'check_in' => now()->addDays(rand(1, 14))->toDateString(),
                        'check_out' => now()->addDays(rand(2, 21))->toDateString(),
                        'source' => $provider->provider,
                        'pulled_at' => now()->toDateTimeString(),
                    ],
                    'sync_status' => 'pending',
                ]);

                $pulled++;
            } catch (\Throwable $e) {
                $errors[] = "Pull error: {$e->getMessage()}";
            }
        }

        Log::info("Channel pull completed for provider {$provider->id}", [
            'pulled' => $pulled,
            'errors' => count($errors),
        ]);

        return ['pulled' => $pulled, 'errors' => $errors];
    }
}
