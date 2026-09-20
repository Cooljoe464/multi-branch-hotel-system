<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\GroupLedger;
use Illuminate\Database\Seeder;

class GroupLedgerSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::all() as $branch) {
            GroupLedger::firstOrCreate(
                ['branch_id' => $branch->id, 'business_date' => today()->toDateString()],
                [
                    'total_room_revenue' => fake()->randomFloat(2, 1000, 10000),
                    'total_pos_revenue' => fake()->randomFloat(2, 100, 1000),
                    'total_tax' => fake()->randomFloat(2, 50, 500),
                    'total_payments' => fake()->randomFloat(2, 1000, 10000),
                    'net_revenue' => fake()->randomFloat(2, 500, 5000),
                    'currency_code' => 'USD',
                    'exchange_rate_to_group' => 1.000000,
                ]
            );
        }
    }
}
