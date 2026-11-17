<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\InventoryHold;
use App\Models\PaymentConnection;
use App\Models\PaymentTransaction;
use App\Models\PublicApiQuote;
use App\Models\PublicPaymentIntent;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PublicPaymentIntentService
{
    /**
     * @param  array{name: string, email: string, phone?: string|null, adults: int, children: int}  $guest
     * @return array{intent: PublicPaymentIntent, access_token: string}
     */
    public function create(ApiKey $apiKey, Branch $branch, int $holdId, array $guest): array
    {
        if ($apiKey->type !== 'secret') {
            throw new AvailabilityException('SECRET_KEY_REQUIRED', 'Hosted checkout requires a server-side secret API key.');
        }

        $paymentService = (new PaymentService)->forBranch($branch);
        $connection = $paymentService->paymentConnection();

        if (! $connection instanceof PaymentConnection) {
            throw new AvailabilityException('PAYMENT_CONNECTION_REQUIRED', 'This property has no active hosted payment connection.');
        }

        if ($apiKey->environment !== $connection->mode) {
            throw new AvailabilityException('PAYMENT_MODE_MISMATCH', 'The API key environment does not match the active payment connection.');
        }

        [$intent, $accessToken] = DB::transaction(function () use ($apiKey, $branch, $holdId, $guest, $connection): array {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $hold = InventoryHold::query()->whereKey($holdId)->lockForUpdate()->firstOrFail();

            abort_unless($hold->branch_id === $branch->id && $hold->api_key_id === $apiKey->id, 404);
            abort_unless($hold->active(), 422, 'The inventory hold has expired or was released.');

            $quote = PublicApiQuote::query()->whereKey($hold->public_api_quote_id)->firstOrFail();
            abort_unless(
                $quote->branch_id === $branch->id
                    && $quote->api_key_id === $apiKey->id
                    && $quote->room_type_id === $hold->room_type_id
                    && $quote->consumed_at !== null
                    && $quote->expires_at->isFuture(),
                422,
                'The quote is expired or does not belong to this hold.',
            );

            $roomType = RoomType::query()->where('branch_id', $branch->id)->whereKey($hold->room_type_id)->firstOrFail();
            abort_if($roomType->max_occupancy < $guest['adults'] + $guest['children'], 422, 'Guest count exceeds the room type occupancy limit.');

            $existing = PublicPaymentIntent::query()
                ->where('api_key_id', $apiKey->id)
                ->where('hold_id', $hold->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PublicPaymentIntent) {
                if ($existing->status === 'pending' && $existing->checkout_url !== null && $existing->expires_at->isFuture()) {
                    throw new AvailabilityException('PAYMENT_INTENT_EXISTS', 'A payment intent already exists for this hold. Retry with its original idempotency key.');
                }

                if ($existing->status === 'paid') {
                    throw new AvailabilityException('PAYMENT_ALREADY_COMPLETED', 'This hold has already been paid.');
                }

                throw new AvailabilityException('PAYMENT_INTENT_EXISTS', 'This hold already has a payment attempt. Release it and create a new hold before retrying checkout.');
            }

            $accessToken = Str::random(64);
            $folio = (new FolioService)->createFolio($branch->id, description: 'Public booking checkout');
            $folio->update(['guest_name' => $guest['name']]);

            $intent = PublicPaymentIntent::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'api_key_id' => $apiKey->id,
                'hold_id' => $hold->id,
                'quote_id' => $hold->public_api_quote_id,
                'payment_connection_id' => $connection->id,
                'folio_id' => $folio->id,
                'guest_name' => $guest['name'],
                'guest_email' => $guest['email'],
                'guest_phone' => $guest['phone'] ?? null,
                'adults' => $guest['adults'],
                'children' => $guest['children'],
                'status' => 'initializing',
                'access_token_hash' => hash('sha256', $accessToken),
                'amount_minor' => $quote->total_minor,
                'currency_code' => $quote->currency_code,
                'expires_at' => $hold->expires_at,
            ]);

            return [$intent, $accessToken];
        }, 3);

        try {
            $folio = $intent->folio;
            if ($folio === null) {
                throw new RuntimeException('The public payment intent folio is missing.');
            }

            $checkout = $paymentService->initializePayment(
                $folio,
                $intent->amount_minor,
                $intent->guest_email,
                null,
                ['public_payment_intent_id' => $intent->id],
            );
            $transaction = PaymentTransaction::query()->where('paystack_reference', $checkout['reference'])->firstOrFail();

            $intent->update([
                'payment_transaction_id' => $transaction->id,
                'gateway_reference' => $checkout['reference'],
                'checkout_url' => $checkout['authorization_url'],
                'status' => 'pending',
            ]);
        } catch (\Throwable $exception) {
            try {
                $transactionCreated = PaymentTransaction::query()
                    ->where('payment_connection_id', $connection->id)
                    ->where('metadata->public_payment_intent_id', $intent->id)
                    ->exists();

                if (! $transactionCreated) {
                    $intent->update(['status' => 'failed']);
                }
            } catch (\Throwable) {
                // Preserve the original gateway or persistence exception for the caller.
            }

            throw $exception;
        }

        return [
            'intent' => $intent->fresh() ?? $intent,
            'access_token' => $accessToken,
        ];
    }

    public function settle(PaymentTransaction $transaction): PublicPaymentIntent
    {
        $intentId = $transaction->metadata['public_payment_intent_id'] ?? null;
        if (! is_int($intentId)) {
            throw new RuntimeException('Public payment transaction has no valid intent reference.');
        }

        return DB::transaction(function () use ($transaction, $intentId): PublicPaymentIntent {
            $intent = PublicPaymentIntent::query()->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if ($intent->status === 'paid' && $intent->reservation_id !== null) {
                return $intent;
            }

            if (! in_array($intent->status, ['initializing', 'pending'], true)
                || ($intent->payment_transaction_id !== null && $intent->payment_transaction_id !== $transaction->id)
                || ($intent->gateway_reference !== null && $intent->gateway_reference !== $transaction->paystack_reference)
                || $transaction->payment_connection_id !== $intent->payment_connection_id
                || $transaction->folio_id !== $intent->folio_id
                || $transaction->amount !== $intent->amount_minor
                || strtoupper($transaction->currency) !== strtoupper($intent->currency_code)
                || ! $intent->expires_at->isFuture()) {
                throw new AvailabilityException('PUBLIC_PAYMENT_EXPIRED', 'The public payment intent expired before provider confirmation.');
            }

            if ($intent->status === 'initializing') {
                $intent->update([
                    'payment_transaction_id' => $transaction->id,
                    'gateway_reference' => $transaction->paystack_reference,
                    'status' => 'pending',
                ]);
            }

            $hold = InventoryHold::query()->whereKey($intent->hold_id)->lockForUpdate()->firstOrFail();
            if (! $hold->active()) {
                throw new AvailabilityException('PUBLIC_HOLD_EXPIRED', 'The inventory hold expired before provider confirmation.');
            }

            $quote = PublicApiQuote::query()->whereKey($intent->quote_id)->firstOrFail();
            $branch = Branch::query()->whereKey($intent->branch_id)->firstOrFail();
            $roomType = RoomType::query()->whereKey($quote->room_type_id)->where('branch_id', $branch->id)->firstOrFail();

            (new InventoryHoldService)->release($hold);

            $rateQuote = $quote->rate_snapshot;
            $nights = $rateQuote['nights'] ?? [];
            $firstNight = is_array($nights) ? ($nights[0] ?? null) : null;
            $firstNightTotal = is_array($firstNight) ? ($firstNight['total_minor'] ?? null) : null;
            $rateQuote['tax_breakdown'] = $quote->tax_snapshot;
            $rateQuote['grand_total_minor'] = $quote->total_minor;
            $reservation = (new AvailabilityService)->reserve(
                branch: $branch,
                roomType: $roomType,
                checkIn: $quote->check_in,
                checkOut: $quote->check_out,
                attributes: [
                    'currency_code' => $quote->currency_code,
                    'guest_name' => $intent->guest_name,
                    'guest_email' => $intent->guest_email,
                    'guest_phone' => $intent->guest_phone,
                    'adults' => $intent->adults,
                    'children' => $intent->children,
                    'room_rate' => is_int($firstNightTotal) ? $firstNightTotal : $quote->total_minor,
                    'total_amount' => $quote->total_minor,
                    'status' => 'confirmed',
                    'source' => 'public_api',
                    'payment_status' => 'pending',
                    'metadata' => ['public_payment_intent_id' => $intent->id],
                ],
                idempotencyKey: 'public-payment-intent:'.$intent->id,
                ratePlan: $quote->ratePlan,
                rateQuote: $rateQuote,
            );

            $folio = Folio::query()->whereKey($intent->folio_id)->lockForUpdate()->firstOrFail();
            $folio->update(['reservation_id' => $reservation->id, 'description' => 'Guest Folio: '.$intent->guest_name]);
            $transaction->update(['reservation_id' => $reservation->id]);
            $intent->update(['reservation_id' => $reservation->id, 'status' => 'paid']);

            app(WebhookDispatcher::class)->dispatch('reservation.confirmed', [
                'reservation_id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'source' => 'public_api',
            ], $branch->id);

            return $intent->fresh() ?? $intent;
        }, 3);
    }

    public function markFailed(PaymentTransaction $transaction): void
    {
        $intentId = $transaction->metadata['public_payment_intent_id'] ?? null;
        if (is_int($intentId)) {
            PublicPaymentIntent::query()->whereKey($intentId)->where('status', 'pending')->update(['status' => 'failed']);
        }
    }
}
