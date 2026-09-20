<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentGuardSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Artisan::call('db:seed', ['--class' => 'RoleSeeder']);

        $this->branch = Branch::factory()->create([
            'is_active' => true,
            'settings' => ['time_zone' => 'Africa/Lagos', 'tax_rate' => 750, 'payment_guard_mode' => 'pay_first'],
        ]);
        $this->user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email_verified_at' => now(),
        ]);
        $this->user->assignRole('Global Admin');
    }

    public function test_payment_guard_edit_page_requires_auth(): void
    {
        $this->get('/settings/payment-guard')->assertRedirect('/login');
    }

    public function test_payment_guard_edit_page_requires_permission(): void
    {
        $userWithoutRole = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($userWithoutRole)->get('/settings/payment-guard')->assertForbidden();
    }

    public function test_update_payment_guard_mode(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/payment-guard', ['payment_guard_mode' => 'pay_after'])
            ->assertRedirect();

        $this->branch->refresh();
        $this->assertEquals('pay_after', $this->branch->settings['payment_guard_mode']);
    }

    public function test_update_rejects_invalid_mode(): void
    {
        $this->actingAs($this->user)
            ->put('/settings/payment-guard', ['payment_guard_mode' => 'invalid'])
            ->assertSessionHasErrors('payment_guard_mode');
    }
}
