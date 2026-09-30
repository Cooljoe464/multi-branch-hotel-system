<?php

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
});

it('persists the user locale from profile settings', function () {
    $this->actingAs($this->user)->patch(route('profile.update'), [
        'name' => $this->user->name,
        'email' => $this->user->email,
        'locale' => 'fr',
    ])->assertRedirect();

    expect($this->user->fresh()?->locale)->toBe('fr');
});

it('rejects unknown locales', function () {
    $this->actingAs($this->user)->patch(route('profile.update'), [
        'name' => $this->user->name,
        'email' => $this->user->email,
        'locale' => 'de',
    ])->assertSessionHasErrors('locale');
});

it('defaults new branches to English', function () {
    expect($this->branch->fresh()?->locale)->toBe('en');
});
