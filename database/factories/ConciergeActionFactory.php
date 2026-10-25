<?php

namespace Database\Factories;

use App\Models\ConciergeAction;
use App\Models\ConciergeThread;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConciergeAction>
 */
class ConciergeActionFactory extends Factory
{
    protected $model = ConciergeAction::class;

    public function definition(): array
    {
        return [
            'concierge_thread_id' => ConciergeThread::factory(),
            'reservation_id' => null,
            'applied_by' => null,
            'type' => ConciergeAction::TYPE_LATE_CHECKOUT,
            'status' => ConciergeAction::STATUS_PENDING_GUEST_CONFIRM,
            'payload' => [],
            'idempotency_key' => 'concierge:'.Str::uuid(),
            'guest_confirmed_at' => null,
            'applied_at' => null,
        ];
    }

    public function maintenance(): static
    {
        return $this->state(fn () => ['type' => ConciergeAction::TYPE_MAINTENANCE]);
    }

    public function applied(): static
    {
        return $this->state(fn () => [
            'status' => ConciergeAction::STATUS_APPLIED,
            'applied_at' => now(),
        ]);
    }
}
