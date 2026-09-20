<?php

namespace Tests;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
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
}
