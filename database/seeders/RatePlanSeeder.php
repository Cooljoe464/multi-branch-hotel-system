<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\RatePlan;
use Illuminate\Database\Seeder;

class RatePlanSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            RatePlan::create([
                'branch_id' => $branch->id,
                'name' => 'Best Available Rate',
                'code' => 'BAR',
                'type' => 'bar',
                'rate_multiplier' => 1.00,
                'valid_from' => now()->toDateString(),
                'is_active' => true,
            ]);

            RatePlan::create([
                'branch_id' => $branch->id,
                'name' => 'Corporate Rate',
                'code' => 'CORP',
                'type' => 'corporate',
                'rate_multiplier' => 0.85,
                'is_negotiable' => true,
                'valid_from' => now()->toDateString(),
                'is_active' => true,
            ]);

            RatePlan::create([
                'branch_id' => $branch->id,
                'name' => 'Weekend Package',
                'code' => 'WPKG',
                'type' => 'package',
                'rate_multiplier' => 1.20,
                'valid_from' => now()->toDateString(),
                'is_active' => true,
            ]);

            RatePlan::create([
                'branch_id' => $branch->id,
                'name' => 'Early Bird Discount',
                'code' => 'EARLY',
                'type' => 'promotional',
                'rate_multiplier' => 0.75,
                'valid_from' => now()->toDateString(),
                'valid_to' => now()->addMonths(3)->toDateString(),
                'is_active' => true,
            ]);
        }
    }
}
