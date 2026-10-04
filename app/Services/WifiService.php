<?php

namespace App\Services;

use App\Events\WifiIssued;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Reservation;
use App\Models\WifiSession;
use App\Support\BranchTime;
use Illuminate\Support\Str;

/**
 * Wi-Fi captive-portal vouchers. Authenticated by confirmation +
 * guest name against an in-house stay; the voucher dies at
 * checkout (valid_to window) and revocation is explicit.
 */
class WifiService
{
    public function issue(Branch $branch, string $confirmationNumber, string $guestName): WifiSession
    {
        $today = BranchTime::today($branch);

        $reservation = Reservation::forBranch($branch->id)
            ->where('confirmation_number', trim($confirmationNumber))
            ->where('status', 'checked_in')
            ->where('check_in_date', '<=', $today)
            ->where('check_out_date', '>', $today)
            ->first();

        if (! $reservation || mb_strtolower(trim($reservation->guest_name)) !== mb_strtolower(trim($guestName))) {
            throw new AvailabilityException('WIFI_AUTH', 'No in-house stay matches these details.');
        }

        $session = WifiSession::create([
            'branch_id' => $branch->id,
            'reservation_id' => $reservation->id,
            'voucher' => strtoupper(Str::random(8)),
            'expires_at' => $reservation->check_out_date->copy()->addDay()->startOfDay(),
        ]);

        event(new WifiIssued($session));

        return $session->fresh() ?? $session;
    }

    public function validate(string $voucher): WifiSession
    {
        $session = WifiSession::where('voucher', strtoupper(trim($voucher)))->first();

        if (! $session || ! $session->usable()) {
            throw new AvailabilityException('WIFI_VOUCHER', 'Voucher is invalid, expired, or revoked.');
        }

        return $session;
    }

    public function revoke(WifiSession $session): WifiSession
    {
        $session->update(['revoked_at' => now()]);

        return $session->fresh() ?? $session;
    }

    /**
     * Revoke all live sessions for a reservation (checkout path).
     * Best-effort: never throws, mirrors MobileKeyService behaviour.
     *
     * @return int Number of sessions revoked.
     */
    public function revokeForReservation(Reservation $reservation): int
    {
        $sessions = WifiSession::where('reservation_id', $reservation->id)
            ->whereNull('revoked_at')
            ->get();

        foreach ($sessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'deprovisioned_at' => $session->deprovisioned_at ?? now(),
            ]);
        }

        return $sessions->count();
    }
}
