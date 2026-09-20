<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();

        $this->admin = $this->makeAdminUser($this->branch);

        $this->frontDesk = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->frontDesk->assignRole('Front Desk');
        $this->frontDesk->branches()->syncWithoutDetaching([$this->branch->id]);
    }

    public function test_front_desk_user_gets_403_on_import_rooms_page(): void
    {
        $this->actingAs($this->frontDesk)
            ->get('/admin/import/rooms')
            ->assertForbidden();
    }

    public function test_front_desk_user_gets_403_on_import_guests_page(): void
    {
        $this->actingAs($this->frontDesk)
            ->get('/admin/import/guests')
            ->assertForbidden();
    }

    public function test_front_desk_user_gets_403_on_import_reservations_page(): void
    {
        $this->actingAs($this->frontDesk)
            ->get('/admin/import/reservations')
            ->assertForbidden();
    }

    public function test_front_desk_user_gets_403_on_branch_wizard_page(): void
    {
        $this->actingAs($this->frontDesk)
            ->get('/admin/branches/create')
            ->assertForbidden();
    }

    public function test_admin_can_access_import_rooms_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/import/rooms')
            ->assertOk();
    }

    public function test_import_template_download_requires_permission(): void
    {
        $this->actingAs($this->frontDesk)
            ->get('/admin/import/template/rooms')
            ->assertForbidden();
    }
}
