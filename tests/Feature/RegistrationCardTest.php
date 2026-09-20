<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RegistrationCard;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationCardTest extends TestCase
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

    public function test_can_show_registration_card_page(): void
    {
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->get("/reservations/{$reservation->id}/registration-card");

        $response->assertStatus(200);
    }

    public function test_can_store_registration_card(): void
    {
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/registration-card", [
            'guest_name' => 'John Doe',
            'id_type' => 'passport',
            'id_number' => 'AB123456',
            'signature_image_url' => 'data:image/png;base64,fakeSignatureData',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('registration_cards', [
            'reservation_id' => $reservation->id,
            'guest_name' => 'John Doe',
            'id_type' => 'passport',
            'id_number' => 'AB123456',
        ]);
    }

    public function test_registration_card_sets_signed_at(): void
    {
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create();

        $this->actingAs($this->user)->post("/reservations/{$reservation->id}/registration-card", [
            'guest_name' => 'Jane Smith',
            'id_type' => 'drivers_license',
            'id_number' => 'DL-987654',
            'signature_image_url' => 'data:image/png;base64,fakeSig',
        ]);

        $card = RegistrationCard::where('reservation_id', $reservation->id)->first();
        $this->assertNotNull($card->signed_at);
    }

    public function test_can_update_existing_registration_card(): void
    {
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create();
        RegistrationCard::factory()->create([
            'reservation_id' => $reservation->id,
            'guest_name' => 'Old Name',
            'id_type' => 'passport',
            'id_number' => 'OLD123',
        ]);

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/registration-card", [
            'guest_name' => 'Updated Name',
            'id_type' => 'national_id',
            'id_number' => 'NEW456',
            'signature_image_url' => 'data:image/png;base64,newSig',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('registration_cards', [
            'reservation_id' => $reservation->id,
            'guest_name' => 'Updated Name',
            'id_number' => 'NEW456',
        ]);
        $this->assertDatabaseCount('registration_cards', 1);
    }

    public function test_validates_required_fields(): void
    {
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/registration-card", []);

        $response->assertSessionHasErrors([
            'guest_name',
            'id_type',
            'id_number',
            'signature_image_url',
        ]);
    }

    public function test_validates_id_type_enum(): void
    {
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create();

        $response = $this->actingAs($this->user)->post("/reservations/{$reservation->id}/registration-card", [
            'guest_name' => 'Test',
            'id_type' => 'invalid_type',
            'id_number' => '123',
            'signature_image_url' => 'data:image/png;base64,sig',
        ]);

        $response->assertSessionHasErrors('id_type');
    }
}
