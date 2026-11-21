<?php

use App\Models\Branch;
use App\Models\User;
use App\Services\Fiscal\AfriInvoiceProvider;
use App\Services\Security\SecretManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->givePermissionTo('fiscal.view');
    $this->user->givePermissionTo('fiscal.retry');
});

it('displays the tax compliance settings page', function () {
    $this->actingAs($this->user)
        ->get(route('settings.tax-compliance.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/TaxCompliance')
            ->has('afri_invoice')
            ->has('firs')
            ->has('preferred_provider')
        );
});

it('updates tax compliance credentials and preferred provider', function () {
    $this->actingAs($this->user)
        ->put(route('settings.tax-compliance.update'), [
            'preferred_provider' => 'afri_invoice',
            'afri_invoice_api_key' => 'secret-test-key-99',
            'afri_invoice_service_id' => 'SRV-HOTEL-LAGOS',
            'afri_invoice_environment' => 'sandbox',
        ])
        ->assertRedirect();

    $secrets = app(SecretManager::class);
    expect($secrets->get($this->branch, AfriInvoiceProvider::SECRET_API_KEY))->toBe('secret-test-key-99')
        ->and($secrets->get($this->branch, AfriInvoiceProvider::SECRET_SERVICE_ID))->toBe('SRV-HOTEL-LAGOS');

    $this->branch->refresh();
    expect($this->branch->settings['fiscal_provider'])->toBe('afri_invoice');
});

it('tests connection to Afri Invoice via diagnostic endpoint', function () {
    Http::fake([
        'https://sandbox.afrinvoice.com/api/v1/auth/verify' => Http::response([
            'merchant_name' => 'Wheatbaker Ikoyi',
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('settings.tax-compliance.test'), [
            'api_key' => 'test-key',
            'service_id' => 'SRV-TEST',
            'environment' => 'sandbox',
        ])
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'merchant_name' => 'Wheatbaker Ikoyi',
        ]);
});
