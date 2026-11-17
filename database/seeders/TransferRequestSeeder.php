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

        foreach ($branches->groupBy(fn (Branch $branch) => $branch->tenant_id) as $tenantBranches) {
            $branchesInTenant = $tenantBranches->values();

            if ($branchesInTenant->count() < 2) {
                continue;
            }

            foreach ($branchesInTenant as $branch) {
                $target = $branchesInTenant->where('id', '!=', $branch->id)->first();
                if (! $target) {
                    continue;
                }

                TransferRequest::factory()->fromBranch($branch->id)->toBranch($target->id)->create();
            }
        }
    }
}
