<?php

namespace App\Services;

use App\Contracts\ChannelDriver;
use App\Events\ChannelPushFailed;
use App\Events\ChannelReconciled;
use App\Events\ParityAlertRaised;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\ChannelMapping;
use App\Models\ChannelMessage;
use App\Models\ChannelProviderModel;
use App\Models\ChannelReconciliationRun;
use App\Models\ChannelReservation;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Services\Channels\FailingChannelDriver;
use App\Services\Channels\LogChannelDriver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Channel reliability: ARI outbox, OTA drivers, inbound webhooks and
 * nightly reconciliation. Transport lives in ChannelDriver
 * implementations; every side effect is keyed so retries, replays and
 * duplicate webhooks converge instead of multiplying.
 */
class ChannelService
{
    /** @var array<string, class-string<ChannelDriver>> */
    protected static array $drivers = [
        'log' => LogChannelDriver::class,
        'failing' => FailingChannelDriver::class,
    ];

    /**
     * @param  class-string<ChannelDriver>  $driver
     */
    public static function swapDriver(string $name, string $driver): void
    {
        self::$drivers[$name] = $driver;
    }

    public function driverFor(?ChannelProviderModel $provider, string $channel): ChannelDriver
    {
        $settings = $provider?->settings;
        $name = is_array($settings) ? ($settings['driver'] ?? 'log') : 'log';
        $name = is_string($name) ? $name : 'log';

        $class = self::$drivers[$name] ?? LogChannelDriver::class;

        return new $class;
    }

    /**
     * Enqueue an ARI push. Same key → same row: retries and dashboard
     * replays never fork a second OTA-side effect.
     *
     * @param  array<string, mixed>  $payload
     */
    public function queueAri(Branch $branch, ?ChannelProviderModel $provider, string $channel, array $payload, string $key): ChannelMessage
    {
        return ChannelMessage::firstOrCreate(
            ['idempotency_key' => $key],
            [
                'branch_id' => $branch->id,
                'channel_provider_id' => $provider?->id,
                'channel' => $channel,
                'kind' => ChannelMessage::KIND_ARI,
                'payload' => $payload,
                'status' => ChannelMessage::STATUS_QUEUED,
            ],
        );
    }

    /**
     * Build + queue ARI for every mapping of a provider over a date
     * range. Returns the queued messages.
     *
     * @return list<ChannelMessage>
     */
    public function queueAriForProvider(ChannelProviderModel $provider, string $from, string $to): array
    {
        $branch = $provider->branch;
        $mappings = ChannelMapping::where('channel_provider_id', $provider->id)->with(['roomType', 'ratePlan'])->get();

        if ($mappings->isEmpty()) {
            return [];
        }

        // Validates the range (throws when empty/inverted).
        (new AvailabilityService)->nights($from, $to);

        $messages = [];

        foreach ($mappings as $mapping) {
            $quote = (new RateEngine)->price($branch, $mapping->ratePlan, $mapping->roomType, $from, $to);

            foreach ($quote['nights'] as $night) {
                $payload = [
                    'channel_room_code' => $mapping->channel_room_code,
                    'channel_rate_code' => $mapping->channel_rate_code,
                    'stay_date' => $night['date'],
                    'sellable' => (new AvailabilityService)->sellableFor($branch, $mapping->roomType, $night['date']),
                    'rate_minor' => $night['total_minor'],
                ];

                $messages[] = $this->queueAri(
                    $branch,
                    $provider,
                    $mapping->channel,
                    $payload,
                    "ari.{$provider->id}.{$mapping->channel_room_code}.{$mapping->channel_rate_code}.{$night['date']}",
                );
            }
        }

        return $messages;
    }

    /**
     * Send one outbox message (a single attempt). Returns true on ack.
     */
    public function sendMessage(ChannelMessage $message): bool
    {
        return DB::transaction(function () use ($message) {
            $locked = ChannelMessage::where('id', $message->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ChannelMessage::STATUS_ACKED) {
                return true;
            }

            $locked->update(['status' => ChannelMessage::STATUS_SENDING, 'attempts' => $locked->attempts + 1]);

            $provider = $locked->channel_provider_id !== null
                ? ChannelProviderModel::find($locked->channel_provider_id)
                : null;

            $result = $this->driverFor($provider, $locked->channel)->pushAri($locked->idempotency_key, $locked->payload);

            if ($result['ok']) {
                $locked->update(['status' => ChannelMessage::STATUS_ACKED, 'last_error' => null]);

                return true;
            }

            $error = $result['error'] ?? 'OTA push failed.';
            $locked->update(['status' => ChannelMessage::STATUS_FAILED, 'last_error' => $error]);

            event(new ChannelPushFailed($locked->fresh() ?? $locked, $error));

            return false;
        });
    }

    /**
     * Dashboard replay: back to queued under the SAME idempotency key,
     * so the OTA still sees one logical push.
     */
    public function replay(ChannelMessage $message): ChannelMessage
    {
        return DB::transaction(function () use ($message) {
            $locked = ChannelMessage::where('id', $message->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ChannelMessage::STATUS_ACKED) {
                throw new AvailabilityException('CHANNEL_REPLAY_ACKED', 'Already-acked messages cannot be replayed.');
            }

            $locked->update(['status' => ChannelMessage::STATUS_QUEUED, 'last_error' => null]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function verifySignature(ChannelProviderModel $provider, string $rawBody, ?string $signature): bool
    {
        $secret = $provider->api_secret;

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * Inbound OTA booking → real PMS reservation. Idempotent on the
     * channel booking id; duplicate deliveries return the original
     * reservation without touching inventory twice.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleInbound(ChannelProviderModel $provider, array $payload): Reservation
    {
        $branch = $provider->branch;

        $bookingId = $payload['channel_booking_id'] ?? null;

        if (! is_string($bookingId) || $bookingId === '') {
            throw new AvailabilityException('CHANNEL_BOOKING_INVALID', 'Inbound payload has no channel booking id.');
        }

        $existing = ChannelReservation::where('channel_provider_id', $provider->id)
            ->where('channel_booking_id', $bookingId)
            ->first();

        if ($existing && $existing->reservation) {
            return $existing->reservation;
        }

        return DB::transaction(function () use ($provider, $branch, $payload, $bookingId, $existing) {
            $roomCode = $payload['channel_room_code'] ?? null;
            $rateCode = $payload['channel_rate_code'] ?? null;
            $checkIn = $payload['check_in'] ?? null;
            $checkOut = $payload['check_out'] ?? null;

            if (! is_string($roomCode) || ! is_string($rateCode) || ! is_string($checkIn) || ! is_string($checkOut)) {
                throw new AvailabilityException('CHANNEL_BOOKING_INVALID', 'Inbound payload is missing room, rate or dates.');
            }

            $mapping = ChannelMapping::where('channel_provider_id', $provider->id)
                ->where('channel_room_code', $roomCode)
                ->where('channel_rate_code', $rateCode)
                ->first();

            if (! $mapping) {
                throw new AvailabilityException('CHANNEL_UNMAPPED', "No mapping for room {$roomCode} / rate {$rateCode}.");
            }

            $quote = (new RateEngine)->price($branch, $mapping->ratePlan, $mapping->roomType, $checkIn, $checkOut);

            $guestName = $payload['guest_name'] ?? null;
            $guestName = is_string($guestName) && $guestName !== '' ? $guestName : 'OTA Guest';

            $reservation = (new AvailabilityService)->reserve(
                branch: $branch,
                roomType: $mapping->roomType,
                checkIn: $checkIn,
                checkOut: $checkOut,
                attributes: [
                    'currency_code' => $branch->currency_code,
                    'guest_name' => $guestName,
                    'guest_email' => $this->stringOrNull($payload['guest_email'] ?? null),
                    'guest_phone' => $this->stringOrNull($payload['guest_phone'] ?? null),
                    'adults' => $this->intOr($payload['adults'] ?? null, 2),
                    'children' => $this->intOr($payload['children'] ?? null, 0),
                    'room_rate' => $quote['nights'][0]['total_minor'] ?? $mapping->roomType->base_rate,
                    'total_amount' => $quote['total_minor'],
                    'status' => 'confirmed',
                    'source' => $provider->provider,
                    'payment_status' => 'pending',
                ],
                idempotencyKey: "channel.{$provider->id}.{$bookingId}",
                ratePlan: $mapping->ratePlan,
                rateQuote: $quote,
            );

            $cardId = $this->tokenizeVirtualCard($reservation, $payload['virtual_card'] ?? null);

            try {
                ChannelReservation::create([
                    'channel_provider_id' => $provider->id,
                    'reservation_id' => $reservation->id,
                    'channel_booking_id' => $bookingId,
                    'raw_payload' => $this->sanitizedPayload($payload),
                    'virtual_card_token_id' => $cardId,
                    'sync_status' => 'synced',
                ]);
            } catch (QueryException $e) {
                // Lost a creation race: the winner's row is the booking.
                $winner = ChannelReservation::where('channel_provider_id', $provider->id)
                    ->where('channel_booking_id', $bookingId)
                    ->first();

                if ($winner && $winner->reservation) {
                    (new AvailabilityService)->release($reservation);
                    $reservation->delete();

                    return $winner->reservation;
                }

                throw $e;
            }

            if ($existing) {
                $existing->update(['reservation_id' => $reservation->id, 'sync_status' => 'synced']);
            }

            return $reservation;
        }, 3);
    }

    /**
     * Nightly parity check: OTA inventory vs PMS truth per mapped room.
     * Drift raises an alert and auto-repushes fresh ARI.
     *
     * @return array{checked: int, drifted: int}
     */
    public function reconcile(ChannelProviderModel $provider, string $from, string $to): array
    {
        $branch = $provider->branch;

        if (! $provider->is_active) {
            return ['checked' => 0, 'drifted' => 0];
        }

        $mappings = ChannelMapping::where('channel_provider_id', $provider->id)->with(['roomType', 'ratePlan'])->get();

        if ($mappings->isEmpty()) {
            return ['checked' => 0, 'drifted' => 0];
        }

        $otaRows = $this->driverFor($provider, $provider->provider)->fetchInventory($branch, $from, $to);
        $otaByRoomDate = [];
        foreach ($otaRows as $row) {
            $otaByRoomDate[$row['channel_room_code'].'.'.$row['stay_date']][] = $row;
        }

        $availability = new AvailabilityService;
        $checked = 0;
        $drifted = 0;
        $byDate = [];

        foreach ($availability->nights($from, $to) as $date) {
            foreach ($mappings->groupBy('channel_room_code') as $roomCode => $roomMappings) {
                /** @var ChannelMapping $first */
                $first = $roomMappings->first();
                $pmsSellable = $availability->sellableFor($branch, $first->roomType, $date);

                foreach ($roomMappings as $mapping) {
                    $ota = $otaByRoomDate[$roomCode.'.'.$date][0] ?? null;
                    $entries = [];

                    if ($ota === null) {
                        $entries[] = $this->driftEntry($date, $mapping, 'OTA has no row for this night.', $pmsSellable, null, null, null);
                    } else {
                        $rateDrift = null;
                        $pmsRate = null;

                        if (is_int($ota['rate_minor'])) {
                            $pmsRate = (new RateEngine)->price($branch, $mapping->ratePlan, $mapping->roomType, $date, Carbon::parse($date)->addDay()->toDateString())['total_minor'];
                            $rateDrift = $pmsRate !== $ota['rate_minor'];
                        }

                        if ($pmsSellable !== $ota['sellable'] || $rateDrift === true) {
                            $entries[] = $this->driftEntry($date, $mapping, 'Availability or rate drift.', $pmsSellable, $ota['sellable'], $pmsRate, $ota['rate_minor']);
                        }
                    }

                    foreach ($entries as $entry) {
                        $byDate[$date][] = $entry;
                        $drifted++;

                        // Stable per-day key: persistent drift re-pushes
                        // once a day, not once per run.
                        $today = Carbon::today()->toDateString();

                        $this->queueAri(
                            $branch,
                            $provider,
                            $mapping->channel,
                            [
                                'channel_room_code' => $mapping->channel_room_code,
                                'channel_rate_code' => $mapping->channel_rate_code,
                                'stay_date' => $date,
                                'sellable' => $pmsSellable,
                                'rate_minor' => (new RateEngine)->price($branch, $mapping->ratePlan, $mapping->roomType, $date, Carbon::parse($date)->addDay()->toDateString())['total_minor'],
                            ],
                            "repush.{$provider->id}.{$mapping->channel_room_code}.{$mapping->channel_rate_code}.{$date}.{$today}",
                        );
                    }

                    $checked++;
                }
            }
        }

        foreach ($availability->nights($from, $to) as $date) {
            $entries = $byDate[$date] ?? [];

            ChannelReconciliationRun::updateOrCreate(
                ['branch_id' => $branch->id, 'channel_provider_id' => $provider->id, 'stay_date' => $date],
                ['diff' => $entries === [] ? null : $entries, 'status' => $entries === [] ? ChannelReconciliationRun::STATUS_OK : ChannelReconciliationRun::STATUS_DRIFT],
            );

            if ($entries !== []) {
                event(new ParityAlertRaised($branch, $provider, $date, $entries));
            }
        }

        event(new ChannelReconciled($branch, $provider, $drifted));

        return ['checked' => $checked, 'drifted' => $drifted];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizedPayload(array $payload): array
    {
        if (isset($payload['virtual_card']) && is_array($payload['virtual_card'])) {
            unset($payload['virtual_card']['pan'], $payload['virtual_card']['cvv'], $payload['virtual_card']['expiry']);
        }

        return $payload;
    }

    /**
     * Tokenize an OTA virtual card. Only the gateway token + last4 are
     * kept; the PAN never touches the database.
     */
    private function tokenizeVirtualCard(Reservation $reservation, mixed $card): ?int
    {
        if (! is_array($card)) {
            return null;
        }

        $token = $card['token'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        $method = PaymentMethod::create([
            'owner_type' => $reservation->getMorphClass(),
            'owner_id' => $reservation->id,
            'driver' => 'channel_virtual_card',
            'token' => $token,
            'brand' => $this->stringOrNull($card['brand'] ?? null),
            'last4' => $this->stringOrNull($card['last4'] ?? null),
        ]);

        return $method->id;
    }

    /**
     * @return array{stay_date: string, channel_room_code: string, channel_rate_code: string, reason: string, pms_sellable: int, ota_sellable: int|null, pms_rate_minor: int|null, ota_rate_minor: int|null}
     */
    private function driftEntry(string $date, ChannelMapping $mapping, string $reason, int $pmsSellable, ?int $otaSellable, ?int $pmsRate, ?int $otaRate): array
    {
        return [
            'stay_date' => $date,
            'channel_room_code' => $mapping->channel_room_code,
            'channel_rate_code' => $mapping->channel_rate_code,
            'reason' => $reason,
            'pms_sellable' => $pmsSellable,
            'ota_sellable' => $otaSellable,
            'pms_rate_minor' => $pmsRate,
            'ota_rate_minor' => $otaRate,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOr(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }
}
