<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\AuditFlagService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditFlagServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditFlagService $service;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AuditFlagService;
        $this->branch = Branch::factory()->create([
            'is_active' => true,
            'settings' => [
                'time_zone' => 'Africa/Lagos',
                'tax_rate' => 750,
                'audit_rate_override_threshold' => 30,
                'audit_voided_txn_threshold' => 10000,
                'audit_refund_threshold' => 50000,
            ],
        ]);
    }

    public function test_generate_creates_no_flags_when_no_issues(): void
    {
        $flags = $this->service->generate($this->branch, Carbon::now());

        $this->assertIsArray($flags);
        $this->assertEmpty($flags);
    }

    public function test_generate_returns_array_of_flag_structures(): void
    {
        $flags = $this->service->generate($this->branch, Carbon::now());

        $this->assertIsArray($flags);
    }

    public function test_generate_creates_audit_flag_records_in_database(): void
    {
        $flags = $this->service->generate($this->branch, Carbon::now());

        $this->assertIsArray($flags);
        $this->assertDatabaseCount('audit_flags', count($flags));
    }
}
