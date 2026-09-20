<?php

namespace Tests\Feature;

use App\Models\AuditFlag;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditFlagTest extends TestCase
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

    public function test_can_list_audit_flags(): void
    {
        AuditFlag::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->get('/audit/flags');
        $response->assertStatus(200);
    }

    public function test_can_review_flag(): void
    {
        $flag = AuditFlag::factory()->forBranch($this->branch->id)->unreviewed()->create();
        $response = $this->actingAs($this->user)->post("/audit/flags/{$flag->id}/review");
        $response->assertRedirect();
        $this->assertDatabaseHas('audit_flags', ['id' => $flag->id, 'is_reviewed' => true]);
    }
}
