<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TransferRequest;
use Database\Seeders\TransferRequestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates transfer requests between branches of the same tenant', function () {
    $tenant = Tenant::factory()->create();

    $downtown = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'is_primary' => true,
    ]);
    $seaside = Branch::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->seed(TransferRequestSeeder::class);

    $transfers = TransferRequest::query()
        ->whereIn('from_branch_id', [$downtown->id, $seaside->id])
        ->whereIn('to_branch_id', [$downtown->id, $seaside->id])
        ->get();

    expect($transfers)->not->toBeEmpty();

    foreach ($transfers as $transfer) {
        expect($transfer->from_branch_id)->not->toBe($transfer->to_branch_id)
            ->and($transfer->fromBranch->tenant_id)->toBe($transfer->toBranch->tenant_id);
    }
});

it('does not create transfer requests between branches of different tenants', function () {
    $firstTenant = Tenant::factory()->create();
    $secondTenant = Tenant::factory()->create();

    $firstBranch = Branch::factory()->create(['tenant_id' => $firstTenant->id]);
    $secondBranch = Branch::factory()->create(['tenant_id' => $secondTenant->id]);

    $this->seed(TransferRequestSeeder::class);

    expect(
        TransferRequest::query()
            ->whereIn('from_branch_id', [$firstBranch->id, $secondBranch->id])
            ->whereIn('to_branch_id', [$firstBranch->id, $secondBranch->id])
            ->count(),
    )->toBe(0);
});
