<?php

namespace App\Services\DoorLock;

use App\Contracts\LockProvider;
use App\Models\Branch;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DoorLockService
{
    private ?Branch $branch = null;

    /**
     * @var array<string, class-string<LockProvider>>
     */
    private static array $providers = [
        'assa_abloy' => AssaAbloyProvider::class,
        'salto' => SaltoProvider::class,
        'duowin' => DuowinProvider::class,
    ];

    public function forBranch(Branch $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    public function issueKey(Reservation $reservation): DoorLockAuditLog
    {
        $branch = $this->resolveBranch($reservation);
        $gateway = $this->getGateway($branch);

        if (! $gateway) {
            throw new \RuntimeException('No active door lock gateway configured for branch: '.$branch->name);
        }

        $provider = $this->getProvider($gateway);

        $room = $reservation->room;
        if (! $room) {
            throw new \RuntimeException('Reservation '.$reservation->confirmation_number.' has no room assigned.');
        }

        $log = DoorLockAuditLog::create([
            'branch_id' => $branch->id,
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'gateway_id' => $gateway->id,
            'action' => 'issue_key',
            'pin_code' => $this->generatePin(),
            'valid_from' => $reservation->actual_check_in_at ?? now(),
            'valid_until' => $reservation->check_out_date->endOfDay(),
            'status' => 'pending',
        ]);

        $success = $provider->issueKey($gateway, $log);

        if ($success) {
            Log::info('Door key issued', [
                'reservation' => $reservation->confirmation_number,
                'room' => $room->number,
                'credential_id' => $log->fresh()?->credential_id,
            ]);
        }

        return $log->fresh() ?? $log;
    }

    public function revokeKey(Reservation $reservation): DoorLockAuditLog
    {
        $branch = $this->resolveBranch($reservation);
        $gateway = $this->getGateway($branch);

        if (! $gateway) {
            throw new \RuntimeException('No active door lock gateway configured for branch: '.$branch->name);
        }

        $latestLog = DoorLockAuditLog::forBranch($branch->id)
            ->forReservation($reservation->id)
            ->forAction('issue_key')
            ->successful()
            ->latest()
            ->first();

        if (! $latestLog) {
            throw new \RuntimeException('No successful key issuance found for reservation '.$reservation->confirmation_number);
        }

        $provider = $this->getProvider($gateway);

        $revokeLog = DoorLockAuditLog::create([
            'branch_id' => $branch->id,
            'reservation_id' => $reservation->id,
            'room_id' => $reservation->room_id,
            'gateway_id' => $gateway->id,
            'action' => 'revoke_key',
            'credential_id' => $latestLog->credential_id,
            'valid_from' => now(),
            'valid_until' => now(),
            'status' => 'pending',
        ]);

        $success = $provider->revokeKey($gateway, $revokeLog);

        if ($success) {
            Log::info('Door key revoked', [
                'reservation' => $reservation->confirmation_number,
                'credential_id' => $latestLog->credential_id,
            ]);
        }

        return $revokeLog->fresh() ?? $revokeLog;
    }

    /**
     * @return array{status: string, active: bool, credential_id?: string|null, valid_from?: Carbon|null, valid_until?: Carbon|null}
     */
    public function getKeyStatus(Reservation $reservation): array
    {
        $branch = $this->resolveBranch($reservation);

        $latestIssue = DoorLockAuditLog::forBranch($branch->id)
            ->forReservation($reservation->id)
            ->forAction('issue_key')
            ->successful()
            ->latest()
            ->first();

        $latestRevoke = DoorLockAuditLog::forBranch($branch->id)
            ->forReservation($reservation->id)
            ->forAction('revoke_key')
            ->latest()
            ->first();

        if (! $latestIssue) {
            return ['status' => 'no_key', 'active' => false];
        }

        if ($latestRevoke && $latestIssue->created_at && $latestRevoke->created_at?->gt($latestIssue->created_at)) {
            return ['status' => 'revoked', 'active' => false];
        }

        if ($latestIssue->valid_until->isPast()) {
            return ['status' => 'expired', 'active' => false];
        }

        return [
            'status' => 'active',
            'active' => true,
            'credential_id' => $latestIssue->credential_id,
            'valid_from' => $latestIssue->valid_from,
            'valid_until' => $latestIssue->valid_until,
        ];
    }

    private function resolveBranch(Reservation $reservation): Branch
    {
        return $this->branch ?? $reservation->branch;
    }

    private function getGateway(Branch $branch): ?DoorLockGateway
    {
        return DoorLockGateway::forBranch($branch->id)
            ->active()
            ->first();
    }

    private function getProvider(DoorLockGateway $gateway): LockProvider
    {
        $providerClass = self::$providers[$gateway->provider] ?? null;

        if (! $providerClass || ! class_exists($providerClass)) {
            throw new \RuntimeException("Unsupported lock provider: {$gateway->provider}");
        }

        return new $providerClass;
    }

    private function generatePin(): string
    {
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }
}
