<?php

namespace App\Services;

use App\Contracts\PaymentDriver;
use App\Models\Branch;
use App\Models\Branding;
use App\Models\Folio;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use App\Services\Payments\PaystackDriver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Payment facade: selects the gateway driver per branch, owns the
 * transaction state machine (pre-auth → capture, partial refunds, voids)
 * and keeps every transition idempotent. Transport lives in drivers.
 */
class PaymentService
{
    private PaymentDriver $driver;

    public function __construct(?PaymentDriver $driver = null)
    {
        $this->driver = $driver ?? new PaystackDriver;
    }

    public function forBranch(Branch $branch): self
    {
        $settings = $branch->settings;
        $override = $settings['payment_driver'] ?? null;
        $default = config('payments.default');

        $name = is_string($override) && $override !== ''
            ? $override
            : (is_string($default) && $default !== '' ? $default : 'paystack');

        $drivers = config('payments.drivers');
        $drivers = is_array($drivers) ? $drivers : [];
        $class = $drivers[$name] ?? PaystackDriver::class;

        if (! is_string($class) || ! is_subclass_of($class, PaymentDriver::class)) {
            $class = PaystackDriver::class;
        }

        $driver = app($class);

        if (! $driver instanceof PaymentDriver) {
            throw new RuntimeException("Payment driver [{$class}] did not resolve.");
        }

        $this->driver = $driver;

        return $this;
    }

    public function driver(): PaymentDriver
    {
        return $this->driver;
    }

    /**
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initializePayment(Folio $folio, int $amount, string $email, ?string $callbackUrl = null): array
    {
        $initialized = $this->driver->initialize(
            $amount,
            $folio->branch->currency_code ?? Branding::instance()->currency_code,
            $email,
            [
                'folio_id' => $folio->id,
                'branch_id' => $folio->branch_id,
                'folio_number' => $folio->folio_number,
            ],
            $callbackUrl,
        );

        PaymentTransaction::create([
            'branch_id' => $folio->branch_id,
            'folio_id' => $folio->id,
            'reservation_id' => $folio->reservation_id,
            'paystack_reference' => $initialized['reference'],
            'paystack_access_code' => $initialized['access_code'],
            'type' => 'charge',
            'driver' => $this->driver->name(),
            'kind' => PaymentTransaction::KIND_SALE,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => $folio->branch->currency_code ?? Branding::instance()->currency_code,
            'customer_email' => $email,
        ]);

        return $initialized;
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $reference): array
    {
        return $this->driver->verify($reference);
    }

    /**
     * Hold funds on a tokenized card without capturing.
     */
    public function preauthorize(Model $owner, Folio $folio, PaymentMethod $method, int $amountMinor): PaymentTransaction
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Pre-auth amount must be positive.');
        }

        return DB::transaction(function () use ($owner, $folio, $method, $amountMinor) {
            $reservation = $folio->relationLoaded('reservation')
                ? $folio->getRelation('reservation')
                : $folio->reservation()->first();
            $email = $reservation instanceof Reservation ? $reservation->guest_email : '';

            $held = $this->driver->preauthorize(
                $amountMinor,
                $folio->branch->currency_code ?? 'NGN',
                $method->token,
                ['folio_id' => $folio->id, 'email' => $email ?? '']
            );

            return PaymentTransaction::create([
                'branch_id' => $folio->branch_id,
                'folio_id' => $folio->id,
                'reservation_id' => $folio->reservation_id,
                'paystack_reference' => $held['reference'],
                'type' => 'preauth',
                'driver' => $this->driver->name(),
                'kind' => PaymentTransaction::KIND_PREAUTH,
                'status' => 'held',
                'amount' => $amountMinor,
                'currency' => $folio->branch->currency_code ?? 'NGN',
                'authorization_code' => $held['authorization_code'],
                'metadata' => ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()],
            ]);
        });
    }

    /**
     * Capture (possibly partially) a held pre-auth.
     */
    public function capture(PaymentTransaction $preauth, int $amountMinor, ?int $postedBy = null): PaymentTransaction
    {
        if ($preauth->kind !== PaymentTransaction::KIND_PREAUTH || $preauth->status !== 'held') {
            throw new InvalidArgumentException('Only held pre-auths can be captured.');
        }

        return DB::transaction(function () use ($preauth, $amountMinor, $postedBy) {
            $locked = PaymentTransaction::where('id', $preauth->id)->lockForUpdate()->firstOrFail();

            $captured = (int) PaymentTransaction::where('parent_id', $locked->id)
                ->where('kind', PaymentTransaction::KIND_CAPTURE)
                ->where('status', 'success')
                ->sum('amount');

            if ($amountMinor <= 0 || $captured + $amountMinor > $locked->amount) {
                throw new InvalidArgumentException('Capture exceeds the held amount.');
            }

            $result = $this->driver->capture((string) $locked->authorization_code, $amountMinor);

            $capture = PaymentTransaction::create([
                'branch_id' => $locked->branch_id,
                'folio_id' => $locked->folio_id,
                'reservation_id' => $locked->reservation_id,
                'paystack_reference' => $result['reference'],
                'type' => 'charge',
                'driver' => $this->driver->name(),
                'kind' => PaymentTransaction::KIND_CAPTURE,
                'parent_id' => $locked->id,
                'status' => 'success',
                'amount' => $result['captured_minor'],
                'currency' => $locked->currency,
                'paid_at' => now(),
            ]);

            $folio = Folio::find($locked->folio_id);
            if ($folio) {
                (new FolioService)->recordPayment(
                    $folio,
                    $result['captured_minor'],
                    'card',
                    $postedBy,
                    $result['reference'],
                );
            }

            if ($captured + $result['captured_minor'] >= $locked->amount) {
                $locked->update(['status' => 'captured']);
            }

            return $capture;
        });
    }

    /**
     * Refund (possibly partially) a successful charge or capture.
     */
    public function refundPayment(PaymentTransaction $charge, int $amountMinor, ?int $postedBy = null): PaymentTransaction
    {
        return DB::transaction(function () use ($charge, $amountMinor, $postedBy) {
            $locked = PaymentTransaction::where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'success') {
                throw new InvalidArgumentException('Only successful payments can be refunded.');
            }

            $refunded = (int) PaymentTransaction::where('parent_id', $locked->id)
                ->where('kind', PaymentTransaction::KIND_REFUND)
                ->where('status', 'success')
                ->sum('amount');

            if ($amountMinor <= 0 || $refunded + $amountMinor > $locked->amount) {
                throw new InvalidArgumentException('Refund exceeds the refundable amount.');
            }

            $result = $this->driver->refund($locked->paystack_reference, $amountMinor);

            $refund = PaymentTransaction::create([
                'branch_id' => $locked->branch_id,
                'folio_id' => $locked->folio_id,
                'reservation_id' => $locked->reservation_id,
                'paystack_reference' => $result['reference'],
                'type' => 'refund',
                'driver' => $this->driver->name(),
                'kind' => PaymentTransaction::KIND_REFUND,
                'parent_id' => $locked->id,
                'status' => 'success',
                'amount' => $result['refunded_minor'],
                'currency' => $locked->currency,
                'paid_at' => now(),
            ]);

            $folio = Folio::find($locked->folio_id);
            if ($folio) {
                (new FolioService)->postDebit(
                    $folio,
                    'refund',
                    "Refund: {$locked->paystack_reference}",
                    $result['refunded_minor'],
                    $postedBy,
                    null,
                    PaymentTransaction::class,
                    $refund->id,
                    null,
                    null,
                    null,
                    'refund.issued',
                );
            }

            return $refund;
        });
    }

    /**
     * @return array{reference: string, captured_minor: int}
     */
    public function capturePreauth(string $authorizationCode, int $amount): array
    {
        return $this->driver->capture($authorizationCode, $amount);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        /** @var array<string, mixed> $data */
        $data = (array) ($payload['data'] ?? []);

        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;
        if (! $reference) {
            return;
        }

        $paymentTx = PaymentTransaction::byReference($reference)->first();
        if (! $paymentTx) {
            return;
        }

        match ($event) {
            'charge.success' => $this->handleChargeSuccess($paymentTx, $data),
            'authorization.success' => $this->handleAuthorizationSuccess($paymentTx, $data),
            'charge.failed' => $paymentTx->markFailed(['webhook_payload' => $payload]),
            'refund.created' => $this->handleRefund($paymentTx, $data, $payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleChargeSuccess(PaymentTransaction $paymentTx, array $data): void
    {
        if ($paymentTx->status === 'success') {
            return;
        }

        $metadata = $data['metadata'] ?? [];

        $authorization = $data['authorization'] ?? [];
        $paymentTx->markSuccess([
            'webhook_payload' => ['data' => $data],
            'authorization_code' => is_array($authorization) ? ($authorization['authorization_code'] ?? null) : null,
            'webhook_event_id' => is_string($data['id'] ?? null) ? $data['id'] : $paymentTx->webhook_event_id,
        ]);

        if ($paymentTx->folio_id) {
            $folioService = new FolioService;
            $folio = Folio::find($paymentTx->folio_id);
            if ($folio) {
                $folioService->postCredit(
                    $folio,
                    'payment',
                    "Paystack payment: {$paymentTx->paystack_reference}",
                    $paymentTx->amount,
                    null,
                    PaymentTransaction::class,
                    $paymentTx->id,
                );
            }
        }

        if ($paymentTx->reservation_id) {
            $reservation = $paymentTx->reservation;
            if ($reservation) {
                $reservation->syncPaymentStatus();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleAuthorizationSuccess(PaymentTransaction $paymentTx, array $data): void
    {
        $authorization = $data['authorization'] ?? [];
        $paymentTx->update([
            'authorization_code' => is_array($authorization) ? ($authorization['authorization_code'] ?? null) : null,
            'metadata' => array_merge($paymentTx->metadata ?? [], [
                'authorization' => $data['authorization'] ?? null,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function handleRefund(PaymentTransaction $paymentTx, array $data, array $payload): void
    {
        $refundAmount = is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : 0;

        PaymentTransaction::create([
            'branch_id' => $paymentTx->branch_id,
            'folio_id' => $paymentTx->folio_id,
            'reservation_id' => $paymentTx->reservation_id,
            'paystack_reference' => 'REF-'.$paymentTx->paystack_reference.'-'.Str::upper(Str::random(4)),
            'type' => 'refund',
            'driver' => $this->driver->name(),
            'kind' => PaymentTransaction::KIND_REFUND,
            'parent_id' => $paymentTx->id,
            'status' => 'success',
            'amount' => $refundAmount,
            'currency' => $paymentTx->currency,
            'paid_at' => isset($data['created_at']) && is_string($data['created_at']) ? Carbon::parse($data['created_at']) : now(),
            'webhook_payload' => $payload,
        ]);

        if ($paymentTx->folio_id) {
            $folioService = new FolioService;
            $folio = Folio::find($paymentTx->folio_id);
            if ($folio) {
                $folioService->postDebit(
                    $folio,
                    'refund',
                    "Refund: {$paymentTx->paystack_reference}",
                    $refundAmount,
                );
            }
        }

        if ($paymentTx->reservation_id) {
            $reservation = $paymentTx->reservation;
            if ($reservation) {
                $reservation->syncPaymentStatus();
            }
        }
    }
}
