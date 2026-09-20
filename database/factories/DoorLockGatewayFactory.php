<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DoorLockGateway;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoorLockGateway> */
class DoorLockGatewayFactory extends Factory
{
    protected $model = DoorLockGateway::class;

    public function definition(): array
    {
        $providers = ['assa_abloy', 'salto', 'dormakaba', 'duowin'];
        $randomProvider = $this->faker->randomElement($providers);
        $provider = is_string($randomProvider) ? $randomProvider : 'assa_abloy';

        $apiUrls = [
            'assa_abloy' => 'https://api.assabloy.com/v1',
            'salto' => 'https://api.salto.com/v2',
            'dormakaba' => 'https://api.dormakaba.com/v1',
            'duowin' => 'http://localhost:3100',
        ];

        return [
            'branch_id' => Branch::factory(),
            'provider' => $provider,
            'api_base_url' => $apiUrls[$provider] ?? 'https://api.example.com/v1',
            'api_key' => strtoupper($this->faker->bothify('apiKey-########-####-####-####-############')),
            'api_secret' => $this->faker->sha256,
            'is_active' => true,
            'settings' => [
                'timeout' => 30,
                'retries' => 3,
                'encryption' => 'aes-256-gcm',
            ],
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch->id]);
    }

    public function assaAbloy(): static
    {
        return $this->state(fn () => [
            'provider' => 'assa_abloy',
            'api_base_url' => 'https://api.assabloy.com/v1',
        ]);
    }

    public function salto(): static
    {
        return $this->state(fn () => [
            'provider' => 'salto',
            'api_base_url' => 'https://api.salto.com/v2',
        ]);
    }

    public function dormakaba(): static
    {
        return $this->state(fn () => [
            'provider' => 'dormakaba',
            'api_base_url' => 'https://api.dormakaba.com/v1',
        ]);
    }

    public function duowin(): static
    {
        return $this->state(fn () => [
            'provider' => 'duowin',
            'api_base_url' => 'http://localhost:3100',
        ]);
    }
}
