<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\ConciergeMessage;
use App\Services\ConciergeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handles Meta delivery status callbacks (delivered / read / failed)
 * for concierge messages. Failed deliveries hand the thread to the
 * human so a guest never waits on an AI reply that was not delivered.
 */
class ProcessWhatsAppDeliveryStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public function __construct(
        private int $branchId,
        private array $statuses = [],
    ) {
        $this->onQueue('concierge');
    }

    public function handle(): void
    {
        $branch = Branch::find($this->branchId);

        if (! $branch instanceof Branch) {
            return;
        }

        foreach ($this->statuses as $status) {
            $messageId = is_string($status['message_id'] ?? null) ? $status['message_id'] : (is_string($status['id'] ?? null) ? $status['id'] : null);
            $messageStatus = is_string($status['status'] ?? null) ? $status['status'] : null;

            if ($messageId === null || $messageStatus === null) {
                continue;
            }

            $message = ConciergeMessage::where('whatsapp_message_id', $messageId)->first();

            if (! $message instanceof ConciergeMessage) {
                continue;
            }

            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $metadata['whatsapp_status'] = $messageStatus;
            $message->update(['metadata' => $metadata]);

            if ($messageStatus === 'failed') {
                $service = ConciergeService::make();
                $service->handOff($message->thread, 'delivery_failed');
            }
        }
    }
}
