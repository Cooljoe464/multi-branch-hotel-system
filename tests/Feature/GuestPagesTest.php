<?php

use App\Models\Branch;
use App\Models\DoNotRent;
use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
});

it('renders the do-not-rent index', function () {
    $guest = Guest::factory()->create(['email' => 'banned@example.com']);

    DoNotRent::create([
        'branch_id' => $this->branch->id,
        'guest_id' => $guest->id,
        'email' => $guest->email,
        'reason' => 'Chargeback fraud',
        'listed_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get("/branches/{$this->branch->id}/dnr")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('guests/Dnr')
            ->has('entries.data', 1));
});

it('lists and removes a do-not-rent entry', function () {
    $this->actingAs($this->user)->post("/branches/{$this->branch->id}/dnr", [
        'email' => 'banned@example.com',
        'reason' => 'Chargeback fraud',
        'scope' => 'branch',
    ])->assertRedirect();

    $entry = DoNotRent::where('email', 'banned@example.com')->firstOrFail();

    expect($entry->branch_id)->toBe($this->branch->id);

    $this->actingAs($this->user)->delete("/branches/{$this->branch->id}/dnr/{$entry->id}")->assertRedirect();

    expect(DoNotRent::where('id', $entry->id)->count())->toBe(0);
});

it('renders the merges index', function () {
    $this->actingAs($this->user)
        ->get("/branches/{$this->branch->id}/guests/merges")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('guests/Merges'));
});
