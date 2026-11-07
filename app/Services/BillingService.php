<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The platform's own subscription billing. Authoritative for what a tenant
 * owes; payment providers only confirm money received.
 */
class BillingService
{
    /**
     * Issue the invoice for the calendar month containing $periodStart. Safe
     * to retry: a second call for the same subscription and month returns the
     * invoice that already exists. A zero-priced (contract) plan is not billed.
     */
    public function generateInvoice(Subscription $subscription, CarbonInterface $periodStart): ?BillingInvoice
    {
        $periodStart = $periodStart->copy()->startOfMonth();
        $subscription->loadMissing('plan');
        $plan = $subscription->plan;

        if ($plan->monthly_price_minor === 0) {
            return null;
        }

        return DB::transaction(function () use ($subscription, $periodStart, $plan): BillingInvoice {
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();

            $existing = BillingInvoice::query()
                ->where('subscription_id', $subscription->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $subtotal = $plan->monthly_price_minor;
            $tax = intdiv($subtotal * config()->integer('billing.vat_basis_points') + 5000, 10000);
            $issuedAt = now();

            $invoice = BillingInvoice::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'number' => sprintf(
                    '%s-%s-%d',
                    config()->string('billing.invoice_prefix'),
                    $periodStart->format('Ym'),
                    $subscription->id,
                ),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodStart->copy()->endOfMonth()->toDateString(),
                'currency' => $plan->currency,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $subtotal + $tax,
                'status' => BillingInvoice::STATUS_ISSUED,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays(config()->integer('billing.invoice_due_days')),
            ]);

            $invoice->lines()->create([
                'tenant_id' => $subscription->tenant_id,
                'description' => $plan->name.' plan, '.$periodStart->format('F Y'),
                'quantity' => 1,
                'unit_amount_minor' => $subtotal,
                'amount_minor' => $subtotal,
            ]);

            return $invoice;
        });
    }

    /**
     * Record a confirmed payment. The amount and currency must match the
     * invoice exactly; a mismatch is rejected for manual review. Replaying the
     * same reference for the same invoice is a no-op.
     *
     * @throws DomainException
     */
    public function reconcilePayment(BillingInvoice $invoice, string $paymentReference, int $amountMinor, string $currency): BillingInvoice
    {
        return DB::transaction(function () use ($invoice, $paymentReference, $amountMinor, $currency): BillingInvoice {
            $locked = BillingInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BillingInvoice::STATUS_PAID) {
                if ($locked->payment_reference === $paymentReference) {
                    return $locked;
                }

                throw new DomainException('Invoice is already paid with a different reference.');
            }

            if ($locked->status === BillingInvoice::STATUS_VOID) {
                throw new DomainException('A void invoice cannot be paid.');
            }

            if ($amountMinor !== $locked->total_minor || strtoupper($currency) !== $locked->currency) {
                throw new DomainException('Payment amount or currency does not match the invoice.');
            }

            $locked->update([
                'status' => BillingInvoice::STATUS_PAID,
                'paid_at' => now(),
                'payment_reference' => $paymentReference,
            ]);

            $this->reactivate($locked);

            return $locked;
        });
    }

    /**
     * Paying an invoice brings a delinquent subscription back to active and
     * extends the paid-through date. A cancelled subscription stays cancelled.
     */
    private function reactivate(BillingInvoice $invoice): void
    {
        $subscription = Subscription::query()->whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();

        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return;
        }

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'past_due_at' => null,
            'grace_ends_at' => null,
            'restricted_at' => null,
            'current_period_ends_at' => $invoice->period_end->copy()->endOfDay(),
        ]);
    }
}
