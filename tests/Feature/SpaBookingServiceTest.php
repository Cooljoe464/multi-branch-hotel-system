<?php

use App\Models\Branch;
use App\Models\SpaAppointment;
use App\Models\SpaService;
use App\Models\SpaTherapist;
use App\Services\SpaBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SpaBookingService;
    $this->branch = Branch::factory()->create();
});

it('books a spa appointment', function () {
    $spaService = SpaService::factory()->create([
        'branch_id' => $this->branch->id,
        'duration_minutes' => 60,
        'price_minor' => 1500000,
    ]);

    $appointment = $this->service->book($this->branch, [
        'guest_name' => 'Jane Doe',
        'spa_service_id' => $spaService->id,
        'scheduled_at' => now()->addDay()->setHour(10)->setMinute(0),
    ]);

    expect($appointment)->toBeInstanceOf(SpaAppointment::class)
        ->and($appointment->duration_minutes)->toBe(60)
        ->and($appointment->price_minor)->toBe(1500000)
        ->and($appointment->status)->toBe('scheduled');
});

it('prevents overlapping therapist appointments', function () {
    $spaService = SpaService::factory()->create([
        'branch_id' => $this->branch->id,
        'duration_minutes' => 60,
    ]);
    $therapist = SpaTherapist::factory()->create(['branch_id' => $this->branch->id]);
    $time = now()->addDay()->setHour(10)->setMinute(0);

    $this->service->book($this->branch, [
        'guest_name' => 'Guest A',
        'spa_service_id' => $spaService->id,
        'spa_therapist_id' => $therapist->id,
        'scheduled_at' => $time,
    ]);

    $this->service->book($this->branch, [
        'guest_name' => 'Guest B',
        'spa_service_id' => $spaService->id,
        'spa_therapist_id' => $therapist->id,
        'scheduled_at' => $time->copy()->addMinutes(30),
    ]);
})->throws(LogicException::class, 'Therapist has an overlapping appointment');

it('reschedules an appointment', function () {
    $spaService = SpaService::factory()->create(['branch_id' => $this->branch->id]);
    $originalTime = now()->addDay()->setHour(10)->setMinute(0);
    $newTime = now()->addDays(2)->setHour(14)->setMinute(0);

    $appointment = $this->service->book($this->branch, [
        'guest_name' => 'Guest',
        'spa_service_id' => $spaService->id,
        'scheduled_at' => $originalTime,
    ]);

    $rescheduled = $this->service->reschedule($appointment, $newTime);

    expect($rescheduled->scheduled_at->format('Y-m-d H:i'))->toBe($newTime->format('Y-m-d H:i'));
});

it('completes an appointment', function () {
    $spaService = SpaService::factory()->create(['branch_id' => $this->branch->id]);

    $appointment = $this->service->book($this->branch, [
        'guest_name' => 'Guest',
        'spa_service_id' => $spaService->id,
        'scheduled_at' => now()->addDay(),
    ]);

    $completed = $this->service->complete($appointment);

    expect($completed->status)->toBe('completed');
});

it('cancels a scheduled appointment', function () {
    $spaService = SpaService::factory()->create(['branch_id' => $this->branch->id]);

    $appointment = $this->service->book($this->branch, [
        'guest_name' => 'Guest',
        'spa_service_id' => $spaService->id,
        'scheduled_at' => now()->addDay(),
    ]);

    $cancelled = $this->service->cancel($appointment);

    expect($cancelled->status)->toBe('cancelled');
});

it('returns therapist schedule for a day', function () {
    $spaService = SpaService::factory()->create([
        'branch_id' => $this->branch->id,
        'duration_minutes' => 30,
    ]);
    $therapist = SpaTherapist::factory()->create(['branch_id' => $this->branch->id]);
    $day = now()->addDay();

    $this->service->book($this->branch, [
        'guest_name' => 'Guest A',
        'spa_service_id' => $spaService->id,
        'spa_therapist_id' => $therapist->id,
        'scheduled_at' => $day->copy()->setHour(10)->setMinute(0),
    ]);

    $this->service->book($this->branch, [
        'guest_name' => 'Guest B',
        'spa_service_id' => $spaService->id,
        'spa_therapist_id' => $therapist->id,
        'scheduled_at' => $day->copy()->setHour(14)->setMinute(0),
    ]);

    $schedule = $this->service->therapistSchedule($therapist, $day);

    expect($schedule)->toHaveCount(2)
        ->and($schedule->first()->guest_name)->toBe('Guest A');
});
