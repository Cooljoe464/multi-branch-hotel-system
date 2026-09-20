<?php

namespace Database\Seeders;

use App\Models\BankProfile;
use App\Models\Branch;
use Illuminate\Database\Seeder;

class BankProfileSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::all() as $branch) {
            BankProfile::firstOrCreate(
                ['branch_id' => $branch->id, 'is_default' => true],
                [
                    'bank_name' => 'Branch Bank',
                    'account_number' => fake()->numerify('########'),
                    'account_name' => fake()->company(),
                    'swift_code' => fake()->bothify('????####'),
                    'sort_code' => fake()->numerify('######'),
                    'currency_code' => 'USD',
                ]
            );
        }
    }
}
