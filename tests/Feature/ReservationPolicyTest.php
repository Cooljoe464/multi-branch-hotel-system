<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationPolicyTest extends TestCase
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

    public function test_check_in_allowed_for_correct_status_and_permission(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertTrue($this->user->can('checkIn', $reservation));
    }

    public function test_check_in_allowed_for_reserved_status(): void
    {
        $reservation = Reservation::factory()->reserved()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertTrue($this->user->can('checkIn', $reservation));
    }

    public function test_check_in_denied_for_checked_in_status(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('checkIn', $reservation));
    }

    public function test_check_in_denied_for_checked_out_status(): void
    {
        $reservation = Reservation::factory()->checkedOut()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('checkIn', $reservation));
    }

    public function test_check_in_denied_for_cancelled_status(): void
    {
        $reservation = Reservation::factory()->cancelled()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('checkIn', $reservation));
    }

    public function test_check_out_allowed_for_checked_in_status(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertTrue($this->user->can('checkOut', $reservation));
    }

    public function test_check_out_denied_for_confirmed_status(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('checkOut', $reservation));
    }

    public function test_check_out_denied_for_checked_out_status(): void
    {
        $reservation = Reservation::factory()->checkedOut()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('checkOut', $reservation));
    }

    public function test_cancel_allowed_for_confirmed_status(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertTrue($this->user->can('cancel', $reservation));
    }

    public function test_cancel_denied_for_checked_out_status(): void
    {
        $reservation = Reservation::factory()->checkedOut()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('cancel', $reservation));
    }

    public function test_cancel_denied_for_cancelled_status(): void
    {
        $reservation = Reservation::factory()->cancelled()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('cancel', $reservation));
    }

    public function test_cross_branch_access_denied(): void
    {
        $otherBranch = Branch::factory()->create();
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $otherBranch->id,
        ]);

        $this->assertFalse($this->user->can('checkIn', $reservation));
        $this->assertFalse($this->user->can('checkOut', $reservation));
        $this->assertFalse($this->user->can('cancel', $reservation));
        $this->assertFalse($this->user->can('view', $reservation));
        $this->assertFalse($this->user->can('update', $reservation));
    }

    public function test_delete_denied_for_checked_in_reservation(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertFalse($this->user->can('delete', $reservation));
    }

    public function test_delete_allowed_for_confirmed_reservation(): void
    {
        $reservation = Reservation::factory()->confirmed()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->assertTrue($this->user->can('delete', $reservation));
    }
}
