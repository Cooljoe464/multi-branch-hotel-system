<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\MaintenanceTicket;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InertiaPropValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
    }

    public function test_rooms_index_returns_correct_component_and_props(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType->id,
            'status' => 'available',
        ]);

        $response = $this->actingAs($this->user)->get('/rooms');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('rooms/Index')
            ->has('rooms')
            ->has('roomTypes')
            ->has('floors')
            ->has('filters'));
    }

    public function test_reservations_index_returns_paginated_props(): void
    {
        Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->get('/reservations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('reservations/Index')
            ->has('reservations')
            ->has('filters'));
    }

    public function test_reservations_create_returns_room_types_and_available_rooms(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType->id,
            'status' => 'available',
        ]);

        $response = $this->actingAs($this->user)->get('/reservations/create');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('reservations/Create')
            ->has('roomTypes')
            ->has('availableRooms')
            ->has('branches'));
    }

    public function test_reservations_show_returns_full_reservation(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->get("/reservations/{$reservation->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('reservations/Show')
            ->has('reservation'));
    }

    public function test_reservations_edit_returns_reservation_with_options(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->get("/reservations/{$reservation->id}/edit");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('reservations/Edit')
            ->has('reservation')
            ->has('roomTypes')
            ->has('availableRooms')
            ->has('branches'));
    }

    public function test_folios_index_returns_paginated_folios(): void
    {
        Folio::create([
            'branch_id' => $this->branch->id,
            'folio_number' => Folio::generateFolioNumber(),
            'type' => 'master',
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->get('/folios');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('folios/Index')
            ->has('folios'));
    }

    public function test_folios_show_returns_transactions_and_child_folios(): void
    {
        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'folio_number' => Folio::generateFolioNumber(),
            'type' => 'master',
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->get("/folios/{$folio->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('folios/Show')
            ->has('folio')
            ->has('transactions')
            ->has('childFolios'));
    }

    public function test_maintenance_index_returns_tickets_and_stats(): void
    {
        MaintenanceTicket::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->get('/maintenance');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('maintenance/Index')
            ->has('tickets')
            ->has('lockedRooms')
            ->has('users')
            ->has('stats')
            ->has('filters'));
    }

    public function test_maintenance_create_returns_rooms(): void
    {
        $response = $this->actingAs($this->user)->get('/maintenance/create');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('maintenance/Create')
            ->has('rooms'));
    }

    public function test_settings_profile_returns_user_data(): void
    {
        $response = $this->actingAs($this->user)->get('/settings/profile');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('settings/Profile')
            ->has('mustVerifyEmail')
            ->has('status'));
    }

    public function test_tape_chart_returns_rooms_grouped_by_floor(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType->id,
            'floor' => '1',
            'status' => 'available',
        ]);

        $response = $this->actingAs($this->user)->get('/tape-chart');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('tape-chart/Index')
            ->has('rooms')
            ->has('reservations')
            ->has('chartData')
            ->has('dates')
            ->has('startDate')
            ->has('endDate'));
    }

    public function test_branch_wizard_returns_empty_state(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/branches/create');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('admin/branches/Create')
            ->has('branch'));
    }

    public function test_import_rooms_page_loads(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/import/rooms');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('admin/import/Rooms')
            ->has('branch'));
    }

    public function test_import_guests_page_loads(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/import/guests');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('admin/import/Guests')
            ->has('branch'));
    }

    public function test_import_reservations_page_loads(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/import/reservations');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('admin/import/Reservations')
            ->has('branch'));
    }

    public function test_cross_branch_search_returns_availability(): void
    {
        Branch::factory()->create(['is_active' => true]);

        $query = '?check_in='.now()->addDay()->toDateString()
            .'&check_out='.now()->addDays(3)->toDateString()
            .'&adults=2';

        $response = $this->actingAs($this->user)->get("/reservations/cross-branch/search{$query}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('reservations/CrossBranchSearch')
            ->has('results')
            ->has('check_in')
            ->has('check_out')
            ->has('adults'));
    }
}
