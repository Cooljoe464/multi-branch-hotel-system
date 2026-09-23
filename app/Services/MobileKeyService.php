<?php

namespace App\Services;

use App\Events\MobileKeyIssued;
use App\Events\MobileKeyRevoked;
use App\Exceptions\AvailabilityException;
use App\Models\MobileKey;
use App\Models\Reservation;
use App\Services\MobileKey\FakeMobileKeyVendor;
use App\Services\MobileKey\MobileKeyVendor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BLE mobile credentials. Issue is idempotent per device; vendor
 * calls never block the stay (plastic fallback + flag); checkout
 * and room moves drive revoke/extend from the reservation flow.
 */
class MobileKeyService
{
    private function vendor(): MobileKeyVendor
    {
        return new FakeMobileKeyVendor;
    }

    /**
     * Issue (or return the existing) credential for a device. The
     * stay must be active; expired stays get no keys.
     *
     * @return array{key: MobileKey, degraded: bool}
     */
    public function issue(Reservation $reservation, string $deviceId): array
    {
        if (! in_array($reservation->status, ['confirmed', 'reserved', 'checked_in'], true)) {
            throw new AvailabilityException('KEY_STAY_INACTIVE', 'Mobile keys need an active reservation.');
        }

        $deviceId = trim($deviceId);

        if ($deviceId === '') {
            throw new AvailabilityException('KEY_DEVICE', 'A device id is required.');
        }

        $key = DB::transaction(function () use ($reservation, $deviceId) {
            $existing = MobileKey::where('reservation_id', $reservation->id)
                ->where('device_id', $deviceId)
                ->where('status', MobileKey::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return MobileKey::create([
                'reservation_id' => $reservation->id,
                'device_id' => $deviceId,
                'key_enc' => Crypt::encryptString(Str::random(48)),
                'valid_from' => now(),
                'valid_to' => $reservation->check_out_date->copy()->addDay()->startOfDay(),
                'status' => MobileKey::STATUS_ACTIVE,
            ]);
        });

        $degraded = false;

        try {
            $this->vendor()->provision($key);
        } catch (\Throwable $e) {
            $degraded = true;
            Log::warning('Mobile key vendor unreachable; plastic fallback.', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }

        event(new MobileKeyIssued($key));

        return ['key' => $key->fresh() ?? $key, 'degraded' => $degraded];
    }

    public function revoke(MobileKey $key): MobileKey
    {
        if ($key->status !== MobileKey::STATUS_ACTIVE) {
            return $key;
        }

        $key->update(['status' => MobileKey::STATUS_REVOKED]);

        try {
            $this->vendor()->revoke($key);
        } catch (\Throwable $e) {
            Log::warning('Mobile key revoke failed at vendor.', ['key_id' => $key->id, 'error' => $e->getMessage()]);
        }

        event(new MobileKeyRevoked($key));

        return $key->fresh() ?? $key;
    }

    /**
     * Revoke every active key on the stay. Runs inside checkout;
     * vendor failures are logged, never thrown.
     */
    public function revokeForReservation(Reservation $reservation): int
    {
        $count = 0;

        foreach (MobileKey::where('reservation_id', $reservation->id)->where('status', MobileKey::STATUS_ACTIVE)->get() as $key) {
            $this->revoke($key);
            $count++;
        }

        return $count;
    }

    /**
     * Extend validity after a room move. The credential itself is
     * room-agnostic (BLE), so only the window moves.
     */
    public function extendForMove(Reservation $reservation): int
    {
        $count = 0;
        $validTo = $reservation->check_out_date->copy()->addDay()->startOfDay();

        foreach (MobileKey::where('reservation_id', $reservation->id)->where('status', MobileKey::STATUS_ACTIVE)->get() as $key) {
            $key->update(['valid_to' => $validTo]);
            $count++;
        }

        return $count;
    }
}
