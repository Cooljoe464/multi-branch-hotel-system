<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TapeChartController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = $request->user()->branch_id;

        $startDate = $request->start_date ? Carbon::parse($request->start_date) : now()->startOfDay();
        $endDate = $startDate->copy()->addDays(13);

        $rooms = Room::forBranch($branchId)
            ->with(['roomType', 'currentReservation'])
            ->where('is_active', true)
            ->orderBy('floor')
            ->orderBy('number')
            ->get();

        $reservations = Reservation::forBranch($branchId)
            ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
            ->where('check_in_date', '<=', $endDate->format('Y-m-d'))
            ->where('check_out_date', '>', $startDate->format('Y-m-d'))
            ->with(['room', 'roomType'])
            ->get();

        $dates = [];
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        $chartData = $this->buildChartData($rooms, $reservations, $dates);

        return Inertia::render('tape-chart/Index', [
            'rooms' => $rooms,
            'reservations' => $reservations,
            'chartData' => $chartData,
            'dates' => $dates,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
        ]);
    }

    private function buildChartData($rooms, $reservations, array $dates): array
    {
        $chart = [];

        foreach ($rooms as $room) {
            $roomData = [
                'id' => $room->id,
                'number' => $room->number,
                'floor' => $room->floor,
                'wing' => $room->wing,
                'status' => $room->status,
                'room_type' => [
                    'id' => $room->roomType->id,
                    'name' => $room->roomType->name,
                    'code' => $room->roomType->code,
                ],
                'cells' => [],
            ];

            foreach ($dates as $date) {
                $carbonDate = Carbon::parse($date);
                $reservation = $reservations->first(function ($res) use ($room, $carbonDate) {
                    return $res->room_id === $room->id
                        && $carbonDate->gte($res->check_in_date)
                        && $carbonDate->lt($res->check_out_date);
                });

                $roomData['cells'][] = [
                    'date' => $date,
                    'is_check_in' => $reservation && $carbonDate->isSameDay($reservation->check_in_date),
                    'is_check_out' => $reservation && $carbonDate->copy()->addDay()->isSameDay($reservation->check_out_date),
                    'reservation' => $reservation ? [
                        'id' => $reservation->id,
                        'confirmation_number' => $reservation->confirmation_number,
                        'guest_name' => $reservation->guest_name,
                        'status' => $reservation->status,
                        'check_in_date' => $reservation->check_in_date->format('Y-m-d'),
                        'check_out_date' => $reservation->check_out_date->format('Y-m-d'),
                    ] : null,
                ];
            }

            $chart[] = $roomData;
        }

        return $chart;
    }
}
