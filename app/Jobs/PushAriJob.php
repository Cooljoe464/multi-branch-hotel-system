<?php

namespace App\Jobs;

use App\Models\ChannelMessage;
use App\Services\ChannelService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Send one outbox message with exponential backoff (1m → 1h, 8
 * attempts). Unique per idempotency key so duplicate dispatches — from
 * the dashboard, the reconciler and the scheduler — collapse into one
 * logical push.
 */
class PushAriJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(
        public int $messageId,
    ) {
        $this->onQueue('channel');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180, 600, 1800, 3600, 3600, 3600, 3600];
    }

    public function uniqueId(): string
    {
        $key = ChannelMessage::where('id', $this->messageId)->value('idempotency_key');

        return is_string($key) ? 'ari.'.$key : 'ari.'.$this->messageId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(): void
    {
        $message = ChannelMessage::find($this->messageId);

        if (! $message) {
            return;
        }

        $acked = (new ChannelService)->sendMessage($message);

        if (! $acked) {
            Log::warning('Channel ARI push failed; retrying with backoff.', [
                'message_id' => $message->id,
                'attempts' => $message->fresh()?->attempts,
            ]);

            $this->fail('OTA push failed.');
        }
    }
}
