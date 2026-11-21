<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SpaAppointment;
use App\Models\SpaService;
use App\Models\SpaTherapist;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Spa appointment booking, rescheduling, and lifecycle management.
 */
class SpaBookingService
{
    /**
     * Book a spa appointment.
     *
     * @param  array<string, mixed>  $data
     */
    public function book(Branch $branch, array $data): SpaAppointment
    {
        return DB::transaction(function () use ($branch, $data) {
            $service = SpaService::findOrFail($data['spa_service_id']);
            $scheduledAt = Carbon::parse($data['scheduled_at']);
            $endTime = $scheduledAt->copy()->addMinutes($service->duration_minutes);

            if (isset($data['spa_therapist_id']) && $data['spa_therapist_id']) {
                $this->validateNoTherapistOverlap(
                    $data['spa_therapist_id'],
                    $scheduledAt,
                    $endTime,
                );
            }

            if (isset($data['spa_treatment_room_id']) && $data['spa_treatment_room_id']) {
                $this->validateNoRoomOverlap(
                    $data['spa_treatment_room_id'],
                    $scheduledAt,
                    $endTime,
                );
            }

            return SpaAppointment::create([
                'branch_id' => $branch->id,
                'reservation_id' => $data['reservation_id'] ?? null,
                'guest_id' => $data['guest_id'] ?? null,
                'guest_name' => $data['guest_name'],
                'spa_service_id' => $service->id,
                'spa_treatment_room_id' => $data['spa_treatment_room_id'] ?? null,
                'spa_therapist_id' => $data['spa_therapist_id'] ?? null,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $service->duration_minutes,
                'price_minor' => $service->price_minor,
                'status' => SpaAppointment::STATUS_SCHEDULED,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Reschedule an existing appointment.
     */
    public function reschedule(SpaAppointment $appointment, CarbonInterface $newTime, ?int $newTherapistId = null): SpaAppointment
    {
        if ($appointment->status !== SpaAppointment::STATUS_SCHEDULED) {
            throw new LogicException('Only scheduled appointments can be rescheduled.');
        }

        return DB::transaction(function () use ($appointment, $newTime, $newTherapistId) {
            $endTime = $newTime->copy()->addMinutes($appointment->duration_minutes);
            $therapistId = $newTherapistId ?? $appointment->spa_therapist_id;

            if ($therapistId) {
                $this->validateNoTherapistOverlap($therapistId, $newTime, $endTime, $appointment->id);
            }

            if ($appointment->spa_treatment_room_id) {
                $this->validateNoRoomOverlap($appointment->spa_treatment_room_id, $newTime, $endTime, $appointment->id);
            }

            $updates = ['scheduled_at' => $newTime];

            if ($newTherapistId !== null) {
                $updates['spa_therapist_id'] = $newTherapistId;
            }

            $appointment->update($updates);

            return $appointment->fresh() ?? $appointment;
        });
    }

    /**
     * Complete an appointment, optionally linking to a folio for billing.
     */
    public function complete(SpaAppointment $appointment, ?int $folioId = null): SpaAppointment
    {
        $updates = ['status' => SpaAppointment::STATUS_COMPLETED];

        if ($folioId !== null) {
            $updates['folio_id'] = $folioId;
        }

        $appointment->update($updates);

        return $appointment->fresh() ?? $appointment;
    }

    /**
     * Cancel a scheduled or in-progress appointment.
     */
    public function cancel(SpaAppointment $appointment): SpaAppointment
    {
        if (! in_array($appointment->status, [SpaAppointment::STATUS_SCHEDULED, SpaAppointment::STATUS_IN_PROGRESS])) {
            throw new LogicException('Only scheduled or in-progress appointments can be cancelled.');
        }

        $appointment->update(['status' => SpaAppointment::STATUS_CANCELLED]);

        return $appointment->fresh() ?? $appointment;
    }

    /**
     * Mark an appointment as no-show.
     */
    public function noShow(SpaAppointment $appointment): SpaAppointment
    {
        $appointment->update(['status' => SpaAppointment::STATUS_NO_SHOW]);

        return $appointment->fresh() ?? $appointment;
    }

    /**
     * Get a therapist's schedule for a given date.
     *
     * @return Collection<int, SpaAppointment>
     */
    public function therapistSchedule(SpaTherapist $therapist, CarbonInterface $date): Collection
    {
        return SpaAppointment::where('spa_therapist_id', $therapist->id)
            ->whereDate('scheduled_at', $date)
            ->whereNotIn('status', [SpaAppointment::STATUS_CANCELLED])
            ->orderBy('scheduled_at')
            ->get();
    }

    private function validateNoTherapistOverlap(int $therapistId, CarbonInterface $start, CarbonInterface $end, ?int $excludeId = null): void
    {
        $existing = SpaAppointment::where('spa_therapist_id', $therapistId)
            ->whereNotIn('status', [SpaAppointment::STATUS_CANCELLED, SpaAppointment::STATUS_NO_SHOW])
            ->where('scheduled_at', '>=', $start->copy()->subHours(12))
            ->where('scheduled_at', '<=', $end->copy()->addHours(12))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($existing as $app) {
            $existingStart = $app->scheduled_at;
            $existingEnd = $app->scheduled_at->copy()->addMinutes($app->duration_minutes);

            if ($start < $existingEnd && $end > $existingStart) {
                throw new LogicException('Therapist has an overlapping appointment at this time.');
            }
        }
    }

    private function validateNoRoomOverlap(int $roomId, CarbonInterface $start, CarbonInterface $end, ?int $excludeId = null): void
    {
        $existing = SpaAppointment::where('spa_treatment_room_id', $roomId)
            ->whereNotIn('status', [SpaAppointment::STATUS_CANCELLED, SpaAppointment::STATUS_NO_SHOW])
            ->where('scheduled_at', '>=', $start->copy()->subHours(12))
            ->where('scheduled_at', '<=', $end->copy()->addHours(12))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($existing as $app) {
            $existingStart = $app->scheduled_at;
            $existingEnd = $app->scheduled_at->copy()->addMinutes($app->duration_minutes);

            if ($start < $existingEnd && $end > $existingStart) {
                throw new LogicException('Treatment room has an overlapping appointment at this time.');
            }
        }
    }
}
