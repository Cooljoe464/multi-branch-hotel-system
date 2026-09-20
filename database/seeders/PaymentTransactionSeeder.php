<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PaymentTransactionSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $reservations = Reservation::where('branch_id', $branch->id)
                ->whereIn('status', ['checked_in', 'checked_out'])
                ->get();

            if ($reservations->isEmpty()) {
                continue;
            }

            $this->createPayments($branch, $reservations);
        }
    }

    /** @param  Collection<int, Reservation>  $reservations */
    private function createPayments(Branch $branch, Collection $reservations): void
    {
        foreach ($reservations as $index => $reservation) {
            $folio = Folio::where('reservation_id', $reservation->id)->first();

            if (! $folio) {
                continue;
            }

            $amount = $reservation->amount_paid > 0 ? $reservation->amount_paid : $reservation->total_amount;

            if ($amount <= 0) {
                $amount = rand(5000, 50000);
            }

            $statuses = ['success', 'success', 'success', 'pending'];
            $status = $statuses[$index % count($statuses)];

            $payment = PaymentTransaction::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'reservation_id' => $reservation->id,
                    'paystack_reference' => 'HMS-'.strtoupper(bin2hex(random_bytes(6))),
                ],
                [
                    'folio_id' => $folio->id,
                    'type' => 'charge',
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => $branch->currency_code,
                    'customer_email' => $reservation->guest_email,
                    'authorization_code' => $status === 'success' ? 'AUTH_'.strtoupper(bin2hex(random_bytes(8))) : null,
                    'paid_at' => $status === 'success' ? now()->subHours(rand(1, 48)) : null,
                    'metadata' => [
                        'reservation_number' => $reservation->confirmation_number,
                        'guest_name' => $reservation->guest_name,
                    ],
                ]
            );
        }
    }
}
