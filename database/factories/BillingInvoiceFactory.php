<?php

namespace Database\Factories;

use App\Models\BillingInvoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingInvoice>
 */
class BillingInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'subscription_id' => Subscription::factory(),
            'number' => 'HOS-'.Str::upper(Str::random(10)),
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'currency' => 'NGN',
            'subtotal_minor' => 10_000_000,
            'tax_minor' => 750_000,
            'total_minor' => 10_750_000,
            'status' => BillingInvoice::STATUS_ISSUED,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BillingInvoice $invoice): void {
            $invoice->tenant_id ??= $invoice->subscription->tenant_id;
        });
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'issued_at' => now()->subDays(20),
            'due_at' => now()->subDays(13),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => BillingInvoice::STATUS_PAID,
            'paid_at' => now(),
            'payment_reference' => 'PAY-'.Str::upper(Str::random(10)),
        ]);
    }
}
