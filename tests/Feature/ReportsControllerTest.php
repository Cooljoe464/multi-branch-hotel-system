<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
    }

    public function test_reports_index_returns_paginated_ledgers(): void
    {
        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->subDays(1)->toDateString(),
            'status' => 'completed',
            'rooms_posted' => 5,
            'total_room_revenue' => 50000,
            'total_tax' => 5000,
            'total_other_charges' => 2000,
            'total_payments' => 40000,
            'net_revenue' => 17000,
        ]);

        $response = $this->actingAs($this->user)->get('/reports');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('analytics/Reports')
            ->has('ledgers'));
    }

    public function test_night_audit_export_requires_dates(): void
    {
        $response = $this->actingAs($this->user)->get('/reports/night-audit/export');

        $response->assertRedirect();
    }

    public function test_night_audit_export_downloads_csv(): void
    {
        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->subDays(1)->toDateString(),
            'status' => 'completed',
            'rooms_posted' => 5,
            'total_room_revenue' => 50000,
            'total_tax' => 5000,
            'total_other_charges' => 2000,
            'total_payments' => 40000,
            'net_revenue' => 17000,
        ]);

        $response = $this->actingAs($this->user)->get('/reports/night-audit/export?start_date='.now()->subDays(7)->toDateString().'&end_date='.now()->toDateString());

        $response->assertStatus(200);
        $response->assertHeaderContains('Content-Type', 'text/csv');
    }

    public function test_financial_export_requires_dates(): void
    {
        $response = $this->actingAs($this->user)->get('/reports/financial/export');

        $response->assertRedirect();
    }

    public function test_financial_export_downloads_csv(): void
    {
        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->subDays(1)->toDateString(),
            'status' => 'completed',
            'rooms_posted' => 5,
            'total_room_revenue' => 50000,
            'total_tax' => 5000,
            'total_other_charges' => 2000,
            'total_payments' => 40000,
            'net_revenue' => 17000,
        ]);

        $response = $this->actingAs($this->user)->get('/reports/financial/export?start_date='.now()->subDays(7)->toDateString().'&end_date='.now()->toDateString());

        $response->assertStatus(200);
        $response->assertHeaderContains('Content-Type', 'text/csv');
    }
}
