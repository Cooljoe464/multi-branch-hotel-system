<?php

namespace Tests\Unit\Models;

use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_vip_status_none_by_default(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 0,
            'total_spent' => 0,
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('none', $guest->fresh()->vip_status);
    }

    public function test_vip_status_silver_at_3_stays(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 3,
            'total_spent' => 0,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('silver', $guest->fresh()->vip_status);
    }

    public function test_vip_status_silver_at_100k_spent(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 0,
            'total_spent' => 100000,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('silver', $guest->fresh()->vip_status);
    }

    public function test_vip_status_gold_at_10_stays(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 10,
            'total_spent' => 0,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('gold', $guest->fresh()->vip_status);
    }

    public function test_vip_status_gold_at_500k_spent(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 0,
            'total_spent' => 500000,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('gold', $guest->fresh()->vip_status);
    }

    public function test_vip_status_platinum_at_25_stays(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 25,
            'total_spent' => 0,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('platinum', $guest->fresh()->vip_status);
    }

    public function test_vip_status_platinum_at_2m_spent(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 0,
            'total_spent' => 2000000,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('platinum', $guest->fresh()->vip_status);
    }

    public function test_vip_status_diamond_at_50_stays(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 50,
            'total_spent' => 0,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('diamond', $guest->fresh()->vip_status);
    }

    public function test_vip_status_diamond_at_5m_spent(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 0,
            'total_spent' => 5000000,
            'vip_status' => 'none',
        ]);

        $guest->evaluateVipStatus();

        $this->assertEquals('diamond', $guest->fresh()->vip_status);
    }

    public function test_increment_stay_updates_stats_in_single_query(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 0,
            'total_nights' => 0,
            'total_spent' => 0,
            'vip_status' => 'none',
        ]);

        $guest->incrementStay(3, 50000);

        $guest->refresh();
        $this->assertEquals(1, $guest->total_stays);
        $this->assertEquals(3, $guest->total_nights);
        $this->assertEquals(50000, $guest->total_spent);
        $this->assertNotNull($guest->last_stayed_at);
    }

    public function test_increment_stay_triggers_vip_evaluation(): void
    {
        $guest = Guest::factory()->create([
            'total_stays' => 2,
            'total_spent' => 0,
            'vip_status' => 'none',
        ]);

        $guest->incrementStay(1, 0);

        $guest->refresh();
        $this->assertEquals(3, $guest->total_stays);
        $this->assertEquals('silver', $guest->vip_status);
    }
}
