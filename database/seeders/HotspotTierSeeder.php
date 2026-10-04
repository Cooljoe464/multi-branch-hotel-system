<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\HotspotTier;
use Illuminate\Database\Seeder;

class HotspotTierSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::all() as $branch) {
            HotspotTier::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => HotspotTier::CODE_FREE],
                [
                    'name' => 'Free Basic',
                    'price_minor' => 0,
                    'rate_up_kbps' => 1024,
                    'rate_down_kbps' => 2048,
                    'quota_mb' => null,
                    'duration_mins' => null,
                    'device_limit' => 2,
                    'is_active' => true,
                ],
            );

            HotspotTier::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => 'premium'],
                [
                    'name' => 'Premium High-Speed',
                    'price_minor' => 2500,
                    'rate_up_kbps' => 10240,
                    'rate_down_kbps' => 20480,
                    'quota_mb' => null,
                    'duration_mins' => null,
                    'device_limit' => 4,
                    'is_active' => true,
                ],
            );
        }
    }
}
