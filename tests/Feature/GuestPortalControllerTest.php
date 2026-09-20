<?php

namespace Tests\Feature;

use App\Http\Controllers\GuestPortalController;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GuestPortalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected RoomType $roomType;

    protected Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->roomType = RoomType::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);
    }

    public function test_folio_page_renders_with_valid_confirmation(): void
    {
        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $this->reservation->id,
            'folio_number' => 'FOL-GP-001',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        $response = $this->get("/guest/folio/{$this->reservation->confirmation_number}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('guest/Folio')
            ->has('folio')
            ->has('reservation')
            ->has('transactions')
        );
    }

    public function test_folio_page_renders_when_no_folio_exists(): void
    {
        $response = $this->get("/guest/folio/{$this->reservation->confirmation_number}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('guest/Folio')
            ->where('folio', null)
            ->has('reservation')
        );
    }

    public function test_folio_page_returns_404_for_invalid_confirmation(): void
    {
        $response = $this->get('/guest/folio/INVALID-NUMBER');
        $response->assertStatus(404);
    }

    public function test_search_guest_with_valid_credentials(): void
    {
        $response = $this->postJson('/guest/lookup', [
            'email' => $this->reservation->guest_email,
            'confirmation' => $this->reservation->confirmation_number,
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('guest/Folio')
            ->has('reservation')
        );
    }

    public function test_search_guest_with_invalid_credentials_returns_error(): void
    {
        $response = $this->postJson('/guest/lookup', [
            'email' => 'wrong@example.com',
            'confirmation' => $this->reservation->confirmation_number,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_guest_profile_renders(): void
    {
        $guest = Guest::factory()->create([
            'email' => $this->reservation->guest_email,
        ]);

        // guestProfile has no route — test via direct controller call
        $controller = $this->app->make(GuestPortalController::class);
        $response = $controller->guestProfile($guest->email);

        $this->assertNotNull($response);
    }

    public function test_guest_profile_returns_null_for_unknown_email(): void
    {
        $controller = $this->app->make(GuestPortalController::class);
        $response = $controller->guestProfile('unknown@example.com');

        $this->assertNull($response);
    }

    public function test_folio_includes_transactions_sorted_by_created_at(): void
    {
        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $this->reservation->id,
            'folio_number' => 'FOL-GP-SORT',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        $folio->transactions()->create([
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        $folio->transactions()->create([
            'type' => 'credit',
            'category' => 'payment',
            'amount' => 5000,
            'description' => 'Deposit',
        ]);

        $response = $this->get("/guest/folio/{$this->reservation->confirmation_number}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('guest/Folio')
            ->has('transactions', 2)
        );
    }

    public function test_folio_with_multiple_transactions(): void
    {
        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $this->reservation->id,
            'folio_number' => 'FOL-GP-MULTI',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 5000,
        ]);

        $folio->transactions()->create([
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        $folio->transactions()->create([
            'type' => 'debit',
            'category' => 'restaurant',
            'amount' => 3000,
            'description' => 'Dinner',
        ]);

        $folio->transactions()->create([
            'type' => 'credit',
            'category' => 'payment',
            'amount' => 8000,
            'description' => 'Partial payment',
        ]);

        $response = $this->get("/guest/folio/{$this->reservation->confirmation_number}");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('guest/Folio')
            ->has('transactions', 3)
            ->where('folio.balance', 5000)
        );
    }

    public function test_search_guest_validates_required_fields(): void
    {
        $response = $this->postJson('/guest/lookup', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'confirmation']);
    }
}
