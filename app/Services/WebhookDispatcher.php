<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Jobs\DeliverWebhookJob;
use App\Models\ApiConsumer;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signed outbound webhooks. Each delivery freezes its payload at
 * dispatch time; the signature is HMAC-SHA256 over canonical JSON,
 * so replays reproduce the same signature while the secret is
 * unchanged. Rotation keeps the previous secret valid for 24h.
 */
class WebhookDispatcher
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';

    public const GRACE_HOURS = 24;

    /**
     * Canonical payload encoding. Flags are fixed so the signer and
     * the receiver hash the same bytes.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            throw new AvailabilityException('WEBHOOK_ENCODE', 'Webhook payload could not be encoded.');
        }

        return $encoded;
    }

    public static function sign(string $encoded, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $encoded, $secret);
    }

    /**
     * Fan out to every active consumer reachable for the branch.
     *
     * @param  array<string, mixed>  $payload
     * @return list<WebhookDelivery>
     */
    public function dispatch(string $event, array $payload, int $branchId): array
    {
        $body = array_merge($payload, [
            'event' => $event,
            'branch_id' => $branchId,
            'dispatched_at' => now()->toIso8601String(),
        ]);

        $deliveries = [];

        foreach ($this->consumersFor($branchId) as $consumer) {
            $secrets = $consumer->webhookSecrets();

            if ($secrets === []) {
                continue;
            }

            $secret = $secrets[0];

            $delivery = DB::transaction(fn () => WebhookDelivery::create([
                'api_consumer_id' => $consumer->id,
                'event' => $event,
                'payload' => $body,
                'signature' => self::sign(self::encode($body), $secret),
                'status' => WebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
            ]));

            DeliverWebhookJob::dispatch($delivery->id, $consumer->id);
            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /**
     * Redeliver the frozen payload. The signature is recomputed over
     * the same bytes, so it matches the original while the secret
     * is unchanged.
     */
    public function replay(WebhookDelivery $delivery): WebhookDelivery
    {
        $consumer = $delivery->consumer;
        $secrets = $consumer->webhookSecrets();

        if ($secrets === []) {
            throw new AvailabilityException('WEBHOOK_NO_SECRET', 'Consumer has no signing secret.');
        }

        $payload = $delivery->payload;

        $delivery->update([
            'signature' => self::sign(self::encode($payload), $secrets[0]),
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
        ]);

        DeliverWebhookJob::dispatch($delivery->id, $delivery->api_consumer_id);

        return $delivery->fresh() ?? $delivery;
    }

    /**
     * @return array{secret: string, grace_until: string}
     */
    public function rotateSecret(ApiConsumer $consumer): array
    {
        $secret = Str::random(64);
        $graceUntil = now()->addHours(self::GRACE_HOURS);

        $consumer->update([
            'webhook_previous_secret' => $consumer->webhook_secret,
            'webhook_secret' => $secret,
            'webhook_grace_until' => $graceUntil,
        ]);

        return ['secret' => $secret, 'grace_until' => $graceUntil->toIso8601String()];
    }

    /**
     * @return list<ApiConsumer>
     */
    private function consumersFor(int $branchId): array
    {
        return array_values(ApiConsumer::active()
            ->whereNotNull('webhook_url')
            ->get()
            ->filter(fn (ApiConsumer $c) => in_array($branchId, $c->reachableBranchIds(), true))
            ->all());
    }
}
