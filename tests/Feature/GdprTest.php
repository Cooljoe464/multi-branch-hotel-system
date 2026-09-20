<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GdprTest extends TestCase
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

    public function test_can_anonymize_guest(): void
    {
        $guest = Guest::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $response = $this->actingAs($this->user)->post('/admin/guest/anonymize', [
            'email' => 'john.doe@example.com',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'first_name' => 'Guest',
            'last_name' => 'Deleted',
        ]);
        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'email' => $guest->fresh()->email,
        ]);
        $this->assertSoftDeleted('guests', ['id' => $guest->id]);
    }

    public function test_anonymize_validates_email_exists(): void
    {
        $response = $this->actingAs($this->user)->post('/admin/guest/anonymize', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_anonymize_logs_activity(): void
    {
        $guest = Guest::factory()->create(['email' => 'jane.doe@example.com']);

        $this->actingAs($this->user)->post('/admin/guest/anonymize', [
            'email' => 'jane.doe@example.com',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'gdpr',
            'description' => 'Guest data anonymized per GDPR request',
        ]);
    }

    public function test_can_export_guest_data(): void
    {
        $guest = Guest::factory()->create([
            'email' => 'export.me@example.com',
            'first_name' => 'Export',
            'last_name' => 'Me',
        ]);

        GuestPreference::factory()->create(['guest_id' => $guest->id]);
        Reservation::factory()->forBranch($this->branch->id)->create([
            'guest_id' => $guest->id,
        ]);

        $response = $this->actingAs($this->user)->get('/admin/guest/export?email=export.me@example.com');

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename="guest-export-'.$guest->id.'.json"');

        $data = $response->json();
        $this->assertArrayHasKey('guest', $data);
        $this->assertArrayHasKey('preferences', $data);
        $this->assertArrayHasKey('reservations', $data);
        $this->assertSame('Export', $data['guest']['first_name']);
    }

    public function test_export_returns_guest_reservations(): void
    {
        $guest = Guest::factory()->create(['email' => 'with.reservations@example.com']);

        Reservation::factory()->forBranch($this->branch->id)->create([
            'guest_id' => $guest->id,
            'confirmation_number' => 'HMS-TEST001',
        ]);

        $response = $this->actingAs($this->user)->get('/admin/guest/export?email=with.reservations@example.com');

        $data = $response->json();
        $this->assertCount(1, $data['reservations']);
        $this->assertSame('HMS-TEST001', $data['reservations'][0]['confirmation_number']);
    }
}
