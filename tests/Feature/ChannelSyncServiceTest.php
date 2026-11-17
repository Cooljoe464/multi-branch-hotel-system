<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChannelMapping;
use App\Models\ChannelMessage;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\ChannelSyncService;
use Database\Factories\ChannelProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_sync_rates_queues_ari_through_the_outbox(): void
    {
        Queue::fake();

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
            'valid_to' => now()->addMonths(2)->toDateString(),
        ]);

        ChannelMapping::create([
            'branch_id' => $this->branch->id,
            'channel_provider_id' => $provider->id,
            'channel' => $provider->provider,
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $ratePlan->id,
            'channel_room_code' => 'DLX',
            'channel_rate_code' => 'BAR',
        ]);

        $result = (new ChannelSyncService)->syncRates($provider);

        $this->assertEmpty($result['errors']);
        $this->assertSame(ChannelSyncService::HORIZON_DAYS, $result['synced']);
        $this->assertSame($result['synced'], ChannelMessage::where('channel_provider_id', $provider->id)->count());
        $this->assertNotNull($provider->fresh()->last_sync_at);
    }

    public function test_sync_rates_reports_missing_mappings(): void
    {
        $provider = (new ChannelProviderFactory)->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $result = (new ChannelSyncService)->syncRates($provider);

        $this->assertSame(0, $result['synced']);
        $this->assertNotEmpty($result['errors']);
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

    public function test_pull_reservations_never_fabricates_bookings(): void
    {
        $provider = (new ChannelProviderFactory)->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $result = (new ChannelSyncService)->pullReservations($provider);

        $this->assertSame(0, $result['pulled']);
        $this->assertNotEmpty($result['errors']);
        $this->assertDatabaseCount('channel_reservations', 0);
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
