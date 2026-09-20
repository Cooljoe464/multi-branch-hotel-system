<?php

namespace Tests\Unit\Models;

use App\Models\Branch;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_number_is_generated_on_creation(): void
    {
        $reservation = Reservation::factory()->create([
            'confirmation_number' => null,
        ]);

        $this->assertNotEmpty($reservation->confirmation_number);
        $this->assertStringStartsWith('HMS-', $reservation->confirmation_number);
    }

    public function test_confirmation_number_is_unique(): void
    {
        $reservations = Reservation::factory()->count(10)->create();

        $numbers = $reservations->pluck('confirmation_number')->unique();
        $this->assertCount(10, $numbers);
    }

    public function test_nights_accessor_calculates_correctly(): void
    {
        $reservation = Reservation::factory()->create([
            'check_in_date' => '2026-09-10',
            'check_out_date' => '2026-09-13',
        ]);

        $this->assertEquals(3, $reservation->nights);
    }

    public function test_nights_accessor_for_single_night(): void
    {
        $reservation = Reservation::factory()->create([
            'check_in_date' => '2026-09-10',
            'check_out_date' => '2026-09-11',
        ]);

        $this->assertEquals(1, $reservation->nights);
    }

    public function test_is_currently_active_for_checked_in(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertTrue($reservation->isCurrentlyActive());
    }

    public function test_is_currently_active_for_reserved(): void
    {
        $reservation = Reservation::factory()->reserved()->create([
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertTrue($reservation->isCurrentlyActive());
    }

    public function test_is_not_currently_active_for_confirmed(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'check_in_date' => now()->addDays(5)->toDateString(),
            'check_out_date' => now()->addDays(8)->toDateString(),
        ]);

        $this->assertFalse($reservation->isCurrentlyActive());
    }

    public function test_is_not_currently_active_for_checked_out(): void
    {
        $reservation = Reservation::factory()->checkedOut()->create([
            'check_in_date' => now()->subDays(5)->toDateString(),
            'check_out_date' => now()->subDays(2)->toDateString(),
        ]);

        $this->assertFalse($reservation->isCurrentlyActive());
    }

    public function test_scope_active_includes_correct_statuses(): void
    {
        $branch = Branch::factory()->create();
        Reservation::factory()->pending()->create(['branch_id' => $branch->id]);
        Reservation::factory()->confirmed()->create(['branch_id' => $branch->id]);
        Reservation::factory()->reserved()->create(['branch_id' => $branch->id]);
        Reservation::factory()->checkedIn()->create(['branch_id' => $branch->id]);
        Reservation::factory()->checkedOut()->create(['branch_id' => $branch->id]);
        Reservation::factory()->cancelled()->create(['branch_id' => $branch->id]);

        $active = Reservation::active()->count();
        $this->assertEquals(4, $active);
    }

    public function test_scope_for_branch_filters_correctly(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        Reservation::factory()->count(3)->create(['branch_id' => $branch1->id]);
        Reservation::factory()->count(2)->create(['branch_id' => $branch2->id]);

        $this->assertEquals(3, Reservation::forBranch($branch1->id)->count());
        $this->assertEquals(2, Reservation::forBranch($branch2->id)->count());
    }

    public function test_scope_checked_in_filters_correctly(): void
    {
        Reservation::factory()->checkedIn()->count(3)->create();
        Reservation::factory()->confirmed()->count(2)->create();

        $this->assertEquals(3, Reservation::checkedIn()->count());
    }
}
