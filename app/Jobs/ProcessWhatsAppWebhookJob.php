<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Services\ConciergeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Webhook turns are acknowledged immediately and processed here so a
 * slow model/tool call never times out Meta's delivery. Meta redelivers
 * aggressively; message-id dedupe makes retries safe.
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $contact
     */
    public function __construct(
        private int $branchId,
        private array $messages,
        private array $contact = [],
    ) {
        $this->onQueue('concierge');
    }

    public function handle(): void
    {
        $branch = Branch::find($this->branchId);

        if (! $branch instanceof Branch) {
            return;
        }

        $service = ConciergeService::make();

        foreach ($this->messages as $message) {
            $service->handleInbound($branch, $message, $this->contact);
        }

        Log::info('WhatsApp webhook processed.', [
            'branch_id' => $branch->id,
            'messages' => count($this->messages),
        ]);
    }
}
