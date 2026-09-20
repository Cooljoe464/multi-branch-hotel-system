<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('reservations.view');
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('reservations.view')
            && $this->belongsToUsersBranch($user, $reservation);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('reservations.create');
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('reservations.update')
            && $this->belongsToUsersBranch($user, $reservation);
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('reservations.delete')
            && $this->belongsToUsersBranch($user, $reservation)
            && ! in_array($reservation->status, ['checked_in']);
    }

    public function checkIn(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('reservations.checkin')
            && $this->belongsToUsersBranch($user, $reservation)
            && in_array($reservation->status, ['confirmed', 'reserved']);
    }

    public function checkOut(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('reservations.checkout')
            && $this->belongsToUsersBranch($user, $reservation)
            && $reservation->status === 'checked_in';
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return $user->hasPermissionTo('reservations.cancel')
            && $this->belongsToUsersBranch($user, $reservation)
            && ! in_array($reservation->status, ['checked_out', 'cancelled']);
    }

    private function belongsToUsersBranch(User $user, Reservation $reservation): bool
    {
        return $user->branches()->where('branches.id', $reservation->branch_id)->exists();
    }
}
