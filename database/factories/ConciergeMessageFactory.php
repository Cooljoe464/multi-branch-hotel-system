<?php

namespace Database\Factories;

use App\Models\ConciergeMessage;
use App\Models\ConciergeThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConciergeMessage>
 */
class ConciergeMessageFactory extends Factory
{
    protected $model = ConciergeMessage::class;

    public function definition(): array
    {
        return [
            'concierge_thread_id' => ConciergeThread::factory(),
            'role' => ConciergeMessage::ROLE_GUEST,
            'body' => fake()->sentence(),
            'tool_calls' => null,
            'metadata' => null,
            'whatsapp_message_id' => null,
            'read_at' => null,
        ];
    }

    public function fromGuest(): static
    {
        return $this->state(fn () => ['role' => ConciergeMessage::ROLE_GUEST]);
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => ConciergeMessage::ROLE_ASSISTANT]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}
