<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DailyLedger;
use Illuminate\Database\Seeder;

class DailyLedgerSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $this->createLedgers($branch);
        }
    }

    private function createLedgers(Branch $branch): void
    {
        for ($daysAgo = 30; $daysAgo >= 0; $daysAgo--) {
            $businessDate = now()->subDays($daysAgo)->toDateString();

            $existing = DailyLedger::where('branch_id', $branch->id)
                ->where('business_date', $businessDate)
                ->first();

            if ($existing) {
                continue;
            }

            $roomsPosted = rand(15, 45);
            $totalRoomRevenue = $roomsPosted * rand(150, 250) * 100;
            $totalTax = (int) ($totalRoomRevenue * ($branch->tax_rate / 100));
            $totalOtherCharges = rand(5000, 25000) * 100;
            $totalPayments = $totalRoomRevenue + $totalOtherCharges;
            $netRevenue = $totalRoomRevenue + $totalOtherCharges - $totalTax;

            $isCompleted = $daysAgo > 0;
            $status = $isCompleted ? 'completed' : 'pending';

            DailyLedger::create([
                'branch_id' => $branch->id,
                'business_date' => $businessDate,
                'status' => $status,
                'rooms_posted' => $roomsPosted,
                'total_room_revenue' => $totalRoomRevenue,
                'total_tax' => $totalTax,
                'total_other_charges' => $totalOtherCharges,
                'total_payments' => $totalPayments,
                'net_revenue' => $netRevenue,
                'started_at' => $isCompleted ? now()->subDays($daysAgo)->subHours(1) : null,
                'completed_at' => $isCompleted ? now()->subDays($daysAgo) : null,
                'errors' => [],
                'metadata' => [
                    'occupied_rooms' => $roomsPosted,
                    'available_rooms' => 80 - $roomsPosted,
                    'occupancy_rate' => round(($roomsPosted / 80) * 100, 2),
                ],
            ]);
        }
    }
}
