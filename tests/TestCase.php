<?php

namespace Tests;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\User;
use Database\Seeders\ChartSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reference data every posting path requires: without chart
        // accounts and posting rules, folio charges cannot journal.
        if (! ChartAccount::query()->exists()) {
            $this->seed(ChartSeeder::class);
        }
    }

    /**
     * Create a user with the Global Admin role and access to the given branch.
     *
     * Behavioural tests use this so route permission middleware and branch
     * isolation checks pass; authorization itself is covered by RouteGatingTest.
     */
    protected function makeAdminUser(Branch $branch, array $attributes = []): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(array_merge(['branch_id' => $branch->id], $attributes));
        $user->assignRole('Global Admin');
        $user->branches()->syncWithoutDetaching([$branch->id]);

        return $user;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Attach a fresh X-Idempotency-Key to mutating test requests so the
     * strict RequireIdempotencyKey middleware passes. Tests that assert
     * idempotency behaviour pass their own explicit key, which is kept.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        if (! in_array(strtoupper((string) $method), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! collect($server)->keys()->contains(
                fn ($name) => str_replace('-', '_', strtolower((string) $name)) === 'http_x_idempotency_key'
            )
        ) {
            $server['HTTP_X-Idempotency-Key'] = (string) Str::uuid();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
