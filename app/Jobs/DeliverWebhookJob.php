<?php

namespace App\Jobs;

use App\Exceptions\UnsafeWebhookUrl;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use App\Services\WebhookSignature;
use App\Services\WebhookUrlGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deliver one signed webhook. Per-consumer uniqueness keeps a
 * property's events ordered; 8 attempts ride exponential backoff,
 * then the delivery parks as failed with the last error.
 */
class DeliverWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(private int $deliveryId, private int $consumerId)
    {
        $this->onQueue('webhooks');
    }

    public function uniqueId(): string
    {
        return "webhooks:consumer:{$this->consumerId}";
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200, 14400, 28800];
    }

    public function handle(?WebhookUrlGuard $guard = null): void
    {
        $guard ??= app(WebhookUrlGuard::class);

        $delivery = WebhookDelivery::with('consumer')->find($this->deliveryId);

        if (! $delivery || $delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        $consumer = $delivery->consumer;

        if (! $consumer->is_active || ! is_string($consumer->webhook_url) || $consumer->webhook_url === '') {
            $delivery->update(['status' => WebhookDelivery::STATUS_FAILED, 'last_error' => 'Consumer inactive or missing URL.']);

            return;
        }

        $delivery->increment('attempts');

        // DNS answers change, so the destination is re-checked on every attempt
        // and the connection is pinned to the addresses that were checked.
        try {
            $destination = $guard->resolve($consumer->webhook_url);
        } catch (UnsafeWebhookUrl $e) {
            $delivery->update(['status' => WebhookDelivery::STATUS_FAILED, 'last_error' => substr($e->getMessage(), 0, 500)]);
            Log::warning('Webhook destination rejected.', ['delivery_id' => $delivery->id, 'reason' => $e->getMessage()]);

            return;
        }

        $body = WebhookDispatcher::encode($delivery->payload);
        $secrets = $consumer->webhookSecrets();

        $headers = [
            'X-Webhook-Event' => $delivery->event,
            'X-Webhook-Delivery' => (string) $delivery->id,
            WebhookDispatcher::SIGNATURE_HEADER => $delivery->signature,
        ];

        if ($secrets !== []) {
            $headers[WebhookSignature::HEADER] = WebhookSignature::header($body, $secrets[0]);
        }

        try {
            $response = Http::timeout(15)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => array_map(
                        fn (string $address): string => "{$destination['host']}:{$destination['port']}:".(str_contains($address, ':') ? "[{$address}]" : $address),
                        $destination['addresses'],
                    )],
                ])
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($consumer->webhook_url);

            if ($response->successful()) {
                $delivery->update(['status' => WebhookDelivery::STATUS_DELIVERED, 'last_error' => null]);

                return;
            }

            throw new \RuntimeException("Receiver responded {$response->status()}.");
        } catch (\Throwable $e) {
            $delivery->update(['last_error' => substr($e->getMessage(), 0, 500)]);

            if ($this->attempts() >= $this->tries) {
                $delivery->update(['status' => WebhookDelivery::STATUS_FAILED]);
                Log::warning('Webhook delivery exhausted.', ['delivery_id' => $delivery->id]);

                return;
            }

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        WebhookDelivery::where('id', $this->deliveryId)
            ->where('status', '!=', WebhookDelivery::STATUS_DELIVERED)
            ->update(['status' => WebhookDelivery::STATUS_FAILED, 'last_error' => substr($e->getMessage(), 0, 500)]);
    }
}
