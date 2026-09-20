<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChannelRate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\ChannelSyncService;
use Database\Factories\ChannelProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
    }

    public function test_sync_rates_updates_is_synced(): void
    {
        $provider = (new ChannelProviderFactory)->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $roomType = RoomType::factory()->create([
            'branch_id' => $this->branch->id,
            'base_rate' => 10000,
        ]);

        $ratePlan = RatePlan::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType->id,
            'rate_multiplier' => 1.0,
            'is_active' => true,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
        ]);

        \DB::table('channel_rates')->insert([
            'channel_provider_id' => $provider->id,
            'rate_plan_id' => $ratePlan->id,
            'room_type_id' => $roomType->id,
            'is_synced' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new ChannelSyncService;
        $result = $service->syncRates($provider);

        $this->assertEquals(1, $result['synced']);
        $this->assertEmpty($result['errors']);

        $rate = ChannelRate::first();
        $this->assertTrue($rate->is_synced);
        $this->assertNotNull($rate->last_synced_at);
    }

    public function test_sync_rates_returns_early_for_inactive_provider(): void
    {
        $provider = (new ChannelProviderFactory)->create([
            'branch_id' => $this->branch->id,
            'is_active' => false,
        ]);

        $service = new ChannelSyncService;
        $result = $service->syncRates($provider);

        $this->assertEquals(0, $result['synced']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_pull_reservations_creates_records(): void
    {
        $provider = (new ChannelProviderFactory)->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $service = new ChannelSyncService;
        $result = $service->pullReservations($provider);

        $this->assertGreaterThanOrEqual(1, $result['pulled']);
        $this->assertEmpty($result['errors']);
        $this->assertDatabaseHas('channel_reservations', [
            'channel_provider_id' => $provider->id,
            'sync_status' => 'pending',
        ]);
    }

    public function test_pull_reservations_returns_early_for_inactive_provider(): void
    {
        $provider = (new ChannelProviderFactory)->create([
            'branch_id' => $this->branch->id,
            'is_active' => false,
        ]);

        $service = new ChannelSyncService;
        $result = $service->pullReservations($provider);

        $this->assertEquals(0, $result['pulled']);
        $this->assertNotEmpty($result['errors']);
    }
}
