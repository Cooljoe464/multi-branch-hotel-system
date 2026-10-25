<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ConciergeThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConciergeThread>
 */
class ConciergeThreadFactory extends Factory
{
    protected $model = ConciergeThread::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'guest_id' => null,
            'reservation_id' => null,
            'assigned_to' => null,
            'channel' => ConciergeThread::CHANNEL_WHATSAPP,
            'status' => ConciergeThread::STATUS_ACTIVE,
            'whatsapp_wa_id' => fake()->unique()->numerify('234##########'),
            'phone_number_id' => fake()->numerify('#############'),
            'last_message_at' => now(),
            'handoff_at' => null,
            'metadata' => null,
        ];
    }

    public function handoff(): static
    {
        return $this->state(fn () => [
            'status' => ConciergeThread::STATUS_HANDOFF,
            'handoff_at' => now(),
        ]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
