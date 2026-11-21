<?php

namespace Database\Factories;

use App\Models\Generator;
use App\Models\GeneratorRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GeneratorRun> */
class GeneratorRunFactory extends Factory
{
    protected $model = GeneratorRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'generator_id' => Generator::factory(),
            'started_at' => now()->subHours(2),
            'stopped_at' => now(),
            'start_meter_hours' => 1000.0,
            'end_meter_hours' => 1002.0,
            'run_hours' => 2.0,
            'fuel_consumed_liters' => 20.0,
            'kwh_generated' => null,
            'reason' => 'power_outage',
            'operator_id' => User::factory(),
        ];
    }
}
