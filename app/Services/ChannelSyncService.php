<?php

namespace App\Services;

use App\Models\ChannelProviderModel;
use Illuminate\Support\Facades\Log;

class ChannelSyncService
{
    public const HORIZON_DAYS = 30;

    /**
     * Queue ARI (rate and availability) pushes for every mapping of the
     * provider through the ChannelService outbox. Delivery, retry and
     * acknowledgement are handled by PushAriJob and the channel driver.
     *
     * @return array{synced: int, errors: list<string>}
     */
    public function syncRates(ChannelProviderModel $provider): array
    {
        if (! $provider->is_active) {
            return ['synced' => 0, 'errors' => ['Provider is inactive.']];
        }

        try {
            $messages = app(ChannelService::class)->queueAriForProvider(
                $provider,
                now()->toDateString(),
                now()->addDays(self::HORIZON_DAYS)->toDateString(),
            );
        } catch (\Throwable $e) {
            return ['synced' => 0, 'errors' => ["Queueing rates failed: {$e->getMessage()}"]];
        }

        if ($messages === []) {
            return ['synced' => 0, 'errors' => ['No channel mappings configured for this provider.']];
        }

        $provider->update(['last_sync_at' => now()]);

        Log::info("Channel ARI queued for provider {$provider->id}", ['queued' => count($messages)]);

        return ['synced' => count($messages), 'errors' => []];
    }

    /**
     * Reservations arrive through the signed inbound webhook; the channel
     * driver contract has no pull operation, so nothing is fabricated here.
     *
     * @return array{pulled: int, errors: list<string>}
     */
    public function pullReservations(ChannelProviderModel $provider): array
    {
        if (! $provider->is_active) {
            return ['pulled' => 0, 'errors' => ['Provider is inactive.']];
        }

        return [
            'pulled' => 0,
            'errors' => ['Pulling is not supported: reservations are delivered by the channel webhook.'],
        ];
    }
}
