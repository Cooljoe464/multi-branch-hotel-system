<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Reservation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class FolioSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $reservations = Reservation::where('branch_id', $branch->id)
                ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
                ->get();

            if ($reservations->isNotEmpty()) {
                $this->createFolios($branch, $reservations);
            }

            $this->createStaffFolios($branch);
            $this->createNonGuestFolios($branch);
        }
    }

    /** @param  Collection<int, Reservation>  $reservations */
    private function createFolios(Branch $branch, Collection $reservations): void
    {
        foreach ($reservations as $reservation) {
            $folio = Folio::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'reservation_id' => $reservation->id,
                    'type' => 'individual',
                ],
                [
                    'status' => $reservation->status === 'checked_out' ? 'closed' : 'open',
                    'description' => "Folio for {$reservation->guest_name}",
                    'balance' => 0,
                    'is_settled' => $reservation->status === 'checked_out',
                    'closed_at' => $reservation->status === 'checked_out' ? now() : null,
                ]
            );

            if ($reservation->is_group_booking && $reservation->group_id) {
                Folio::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'reservation_id' => $reservation->id,
                        'type' => 'master',
                    ],
                    [
                        'status' => 'open',
                        'description' => "Master folio for group {$reservation->group_id}",
                        'balance' => 0,
                        'is_settled' => false,
                    ]
                );
            }
        }
    }

    private function createStaffFolios(Branch $branch): void
    {
        $staffNames = [
            'Hotel Manager',
            'Front Desk Supervisor',
            'Housekeeping Manager',
            'Duty Manager',
        ];

        foreach ($staffNames as $name) {
            Folio::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'type' => 'staff',
                    'guest_name' => $name,
                ],
                [
                    'status' => 'open',
                    'description' => 'House account - '.$name,
                    'balance' => 0,
                    'is_settled' => false,
                ]
            );
        }
    }

    private function createNonGuestFolios(Branch $branch): void
    {
        $accounts = [
            ['guest_name' => 'Lagos Business Partners', 'description' => 'Corporate account - quarterly meetings'],
            ['guest_name' => 'Hotel Maintenance Fund', 'description' => 'Internal maintenance expenses'],
            ['guest_name' => 'Staff Welfare Account', 'description' => 'Staff welfare and events'],
        ];

        foreach ($accounts as $account) {
            Folio::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'type' => 'non_guest',
                    'guest_name' => $account['guest_name'],
                ],
                [
                    'status' => 'open',
                    'description' => $account['description'],
                    'balance' => 0,
                    'is_settled' => false,
                ]
            );
        }
    }
}
