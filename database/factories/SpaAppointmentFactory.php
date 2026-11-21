<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SpaAppointment;
use App\Models\SpaService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpaAppointment> */
class SpaAppointmentFactory extends Factory
{
    protected $model = SpaAppointment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'reservation_id' => null,
            'guest_id' => null,
            'guest_name' => fake()->name(),
            'spa_service_id' => SpaService::factory(),
            'spa_treatment_room_id' => null,
            'spa_therapist_id' => null,
            'scheduled_at' => fake()->dateTimeBetween('now', '+7 days'),
            'duration_minutes' => 60,
            'price_minor' => fake()->numberBetween(500000, 5000000),
            'status' => 'scheduled',
            'folio_id' => null,
            'notes' => null,
        ];
    }
}
