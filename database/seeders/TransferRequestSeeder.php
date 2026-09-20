<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\TransferRequest;
use Illuminate\Database\Seeder;

class TransferRequestSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        if ($branches->count() < 2) {
            return;
        }

        foreach ($branches as $branch) {
            $target = $branches->where('id', '!=', $branch->id)->first();
            if (! $target) {
                continue;
            }

            TransferRequest::factory()->fromBranch($branch->id)->toBranch($target->id)->create();
        }
    }
}
