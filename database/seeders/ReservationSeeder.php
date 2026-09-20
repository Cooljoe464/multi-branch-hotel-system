<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class ReservationSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $guests = Guest::all();

        if ($guests->isEmpty()) {
            return;
        }

        foreach ($branches as $branch) {
            $rooms = Room::where('branch_id', $branch->id)->get();
            $roomTypes = RoomType::where('branch_id', $branch->id)->get();

            if ($rooms->isEmpty() || $roomTypes->isEmpty()) {
                continue;
            }

            $this->createReservations($branch, $rooms, $roomTypes, $guests);
        }
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, RoomType>  $roomTypes
     * @param  Collection<int, Guest>  $guests
     */
    private function createReservations(Branch $branch, Collection $rooms, Collection $roomTypes, Collection $guests): void
    {
        $statuses = ['confirmed', 'confirmed', 'checked_in', 'checked_in', 'checked_out', 'cancelled'];
        $sources = ['direct', 'booking.com', 'expedia', 'phone', 'walk_in'];

        for ($i = 0; $i < 10; $i++) {
            $guest = $guests[$i % $guests->count()] ?? null;
            $room = $rooms[$i % $rooms->count()] ?? null;
            $roomType = $room ? ($roomTypes->firstWhere('id', $room->room_type_id) ?? $roomTypes->first()) : null;

            if (! $guest || ! $room || ! $roomType) {
                continue;
            }

            $status = $statuses[$i % count($statuses)];

            $checkInDate = match ($status) {
                'checked_out' => now()->subDays(rand(5, 30))->toDateString(),
                'checked_in' => now()->subDays(rand(0, 3))->toDateString(),
                'cancelled' => now()->addDays(rand(1, 30))->toDateString(),
                default => now()->addDays(rand(1, 14))->toDateString(),
            };

            $checkOutDate = match ($status) {
                'checked_out' => now()->subDays(rand(1, 5))->toDateString(),
                'checked_in' => now()->addDays(rand(1, 5))->toDateString(),
                'cancelled' => now()->addDays(rand(3, 10))->toDateString(),
                default => now()->addDays(rand(2, 7))->toDateString(),
            };

            $nights = (int) (new \DateTime($checkInDate))->diff(new \DateTime($checkOutDate))->days;
            $roomRate = $roomType->base_rate;
            $totalAmount = $roomRate * $nights;
            $amountPaid = match ($status) {
                'checked_out' => $totalAmount,
                'checked_in' => (int) ($totalAmount * 0.5),
                'cancelled' => 0,
                default => 0,
            };

            $paymentStatus = match ($status) {
                'checked_out' => 'paid',
                'checked_in' => 'partial',
                'cancelled' => 'refunded',
                default => 'pending',
            };

            $specialRequests = [];
            if ($guest->vip_status === 'diamond') {
                $specialRequests[] = 'VIP guest - priority service';
            }
            $dietaryRestrictions = is_array(json_decode($guest->dietary_restrictions ?? '', true)) ? json_decode($guest->dietary_restrictions ?? '', true) : [];
            if (count($dietaryRestrictions) > 0) {
                $specialRequests[] = 'Dietary restrictions apply';
            }

            $reservation = Reservation::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'guest_id' => $guest->id,
                    'check_in_date' => $checkInDate,
                ],
                [
                    'room_id' => $room->id,
                    'room_type_id' => $roomType->id,
                    'status' => $status,
                    'source' => $sources[$i % count($sources)],
                    'guest_name' => $guest->full_name,
                    'guest_email' => $guest->email,
                    'guest_phone' => $guest->phone,
                    'guest_notes' => ($guest->special_notes && is_array(json_decode($guest->special_notes, true)) ? implode(', ', array_filter(json_decode($guest->special_notes, true), fn ($v) => is_string($v))) : null),
                    'adults' => rand(1, 2),
                    'children' => $i % 3 === 0 ? 1 : 0,
                    'check_out_date' => $checkOutDate,
                    'room_rate' => $roomRate,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'payment_status' => $paymentStatus,
                    'is_group_booking' => $i % 5 === 0,
                    'group_id' => $i % 5 === 0 ? 'GRP-'.strtoupper(bin2hex(random_bytes(4))) : null,
                    'special_requests' => $specialRequests,
                ]
            );

            if ($status === 'checked_in' || $status === 'checked_out') {
                $room->update(['status' => $status === 'checked_in' ? 'occupied' : 'dirty']);
            }
        }

        $this->createCrossBranchStays($branch, $rooms, $roomTypes, $guests);
    }

    /**
     * Create cross-branch guest stays for VIP guests who stay at multiple properties.
     *
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, RoomType>  $roomTypes
     * @param  Collection<int, Guest>  $guests
     */
    private function createCrossBranchStays(Branch $branch, Collection $rooms, Collection $roomTypes, Collection $guests): void
    {
        $crossBranchIndices = [4, 10];
        $statuses = ['checked_out', 'checked_in'];

        foreach ($crossBranchIndices as $idx) {
            $guest = $guests[$idx] ?? null;
            if (! $guest) {
                continue;
            }

            $existingCount = Reservation::where('branch_id', $branch->id)
                ->where('guest_id', $guest->id)
                ->count();

            if ($existingCount >= 2) {
                continue;
            }

            $room = $rooms->random();
            $roomType = $roomTypes->firstWhere('id', $room->room_type_id) ?? $roomTypes->first();
            if (! $roomType) {
                continue;
            }

            $status = $statuses[array_rand($statuses)];
            $checkInDate = match ($status) {
                'checked_out' => now()->subDays(rand(5, 30))->toDateString(),
                default => now()->subDays(rand(0, 3))->toDateString(),
            };
            $checkOutDate = match ($status) {
                'checked_out' => now()->subDays(rand(1, 5))->toDateString(),
                default => now()->addDays(rand(1, 5))->toDateString(),
            };

            $nights = (int) (new \DateTime($checkInDate))->diff(new \DateTime($checkOutDate))->days;
            $roomRate = $roomType->base_rate;
            $totalAmount = $roomRate * $nights;

            Reservation::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'guest_id' => $guest->id,
                    'check_in_date' => $checkInDate,
                ],
                [
                    'room_id' => $room->id,
                    'room_type_id' => $roomType->id,
                    'status' => $status,
                    'source' => 'direct',
                    'guest_name' => $guest->full_name,
                    'guest_email' => $guest->email,
                    'guest_phone' => $guest->phone,
                    'adults' => 1,
                    'children' => 0,
                    'check_out_date' => $checkOutDate,
                    'room_rate' => $roomRate,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $status === 'checked_out' ? $totalAmount : (int) ($totalAmount * 0.5),
                    'payment_status' => $status === 'checked_out' ? 'paid' : 'partial',
                ]
            );
        }
    }
}
