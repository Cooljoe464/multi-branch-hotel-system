<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\DoorLock\DuowinProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuowinProviderTest extends TestCase
{
    use RefreshDatabase;

    private DuowinProvider $provider;

    private Branch $branch;

    private DoorLockGateway $gateway;

    private Room $room;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new DuowinProvider;
        $this->branch = Branch::factory()->create(['is_active' => true, 'settings' => ['time_zone' => 'Africa/Lagos', 'tax_rate' => 750]]);
        $this->gateway = DoorLockGateway::factory()->duowin()->forBranch($this->branch)->create();
        $this->room = Room::factory()->forBranch($this->branch->id)->create(['status' => 'occupied']);
        $this->reservation = Reservation::factory()->forBranch($this->branch->id)->forGuest()->create([
            'room_id' => $this->room->id,
            'status' => 'checked_in',
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ]);
    }

    public function test_get_provider_name_returns_duowin(): void
    {
        $this->assertEquals('duowin', $this->provider->getProviderName());
    }

    public function test_issue_key_returns_log_with_status(): void
    {
        $log = DoorLockAuditLog::factory()->forReservation($this->reservation)->forRoom($this->room)->forGateway($this->gateway)->create([
            'action' => 'issue',
            'status' => 'pending',
            'valid_from' => now(),
            'valid_until' => now()->addDays(1),
            'pin_code' => '1234',
        ]);

        $result = $this->provider->issueKey($this->gateway, $log);

        $this->assertIsBool($result);
        $this->assertDatabaseHas('door_lock_audit_logs', ['id' => $log->id]);
    }

    public function test_revoke_key_without_credential_id_fails(): void
    {
        $log = DoorLockAuditLog::factory()->forReservation($this->reservation)->forRoom($this->room)->forGateway($this->gateway)->create([
            'action' => 'issue',
            'status' => 'success',
            'credential_id' => null,
        ]);

        $result = $this->provider->revokeKey($this->gateway, $log);

        $this->assertFalse($result);
    }

    public function test_extend_key_without_credential_id_fails(): void
    {
        $log = DoorLockAuditLog::factory()->forReservation($this->reservation)->forRoom($this->room)->forGateway($this->gateway)->create([
            'action' => 'issue',
            'status' => 'success',
            'credential_id' => null,
        ]);

        $result = $this->provider->extendKey($this->gateway, $log);

        $this->assertFalse($result);
    }
}
