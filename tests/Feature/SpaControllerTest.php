<?php

use App\Models\Branch;
use App\Models\SpaAppointment;
use App\Models\SpaService;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->branches()->attach($this->branch);
    $this->user->givePermissionTo('reservations.view');
    $this->user->givePermissionTo('reservations.create');
    $this->user->givePermissionTo('reservations.update');
    $this->user->givePermissionTo('reservations.cancel');
});

it('renders the spa appointments list', function () {
    SpaService::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('spa.appointments.index', $this->branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('spa/Appointments')
            ->has('services')
        );
});

it('books a spa appointment via controller', function () {
    $service = SpaService::factory()->create([
        'branch_id' => $this->branch->id,
        'duration_minutes' => 60,
        'price_minor' => 2000000,
    ]);

    $this->actingAs($this->user)
        ->post(route('spa.book', $this->branch), [
            'guest_name' => 'Alice Walker',
            'spa_service_id' => $service->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('spa_appointments', [
        'branch_id' => $this->branch->id,
        'guest_name' => 'Alice Walker',
        'spa_service_id' => $service->id,
        'price_minor' => 2000000,
        'status' => 'scheduled',
    ]);
});

it('completes and cancels an appointment via controller', function () {
    $service = SpaService::factory()->create(['branch_id' => $this->branch->id]);
    $app = SpaAppointment::factory()->create([
        'branch_id' => $this->branch->id,
        'spa_service_id' => $service->id,
        'status' => 'scheduled',
    ]);

    $this->actingAs($this->user)
        ->post(route('spa.complete', [$this->branch, $app]))
        ->assertRedirect();

    expect($app->fresh()->status)->toBe('completed');

    $app2 = SpaAppointment::factory()->create([
        'branch_id' => $this->branch->id,
        'spa_service_id' => $service->id,
        'status' => 'scheduled',
    ]);

    $this->actingAs($this->user)
        ->post(route('spa.cancel', [$this->branch, $app2]))
        ->assertRedirect();

    expect($app2->fresh()->status)->toBe('cancelled');
});
