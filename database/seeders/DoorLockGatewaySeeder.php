<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DoorLockGateway;
use Illuminate\Database\Seeder;

class DoorLockGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $this->createGateway($branch);
        }
    }

    private function createGateway(Branch $branch): void
    {
        DoorLockGateway::firstOrCreate(
            [
                'branch_id' => $branch->id,
            ],
            [
                'provider' => 'assa_abloy',
                'api_base_url' => 'https://api.assabloy.com/v1',
                'api_key' => 'ABLOY-'.strtoupper(bin2hex(random_bytes(8))),
                'api_secret' => sha1($branch->code.'assa_abloy_secret'),
                'is_active' => true,
                'settings' => [
                    'timeout' => 30,
                    'retries' => 3,
                    'encryption' => 'aes-256-gcm',
                    'auto_lock_delay' => 15,
                ],
            ]
        );
    }
}
