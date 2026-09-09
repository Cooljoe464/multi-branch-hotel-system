<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('branch has correct attributes', function () {
    $branch = Branch::factory()->create([
        'name' => 'Test Hotel',
        'code' => 'TH-001',
        'slug' => 'test-hotel',
        'city' => 'Miami',
        'country' => 'US',
        'currency_code' => 'USD',
        'currency_symbol' => '$',
        'tax_rate' => 7.5,
        'is_active' => true,
        'is_primary' => true,
    ]);

    expect($branch->name)->toBe('Test Hotel');
    expect($branch->code)->toBe('TH-001');
    expect($branch->slug)->toBe('test-hotel');
    expect($branch->city)->toBe('Miami');
    expect($branch->country)->toBe('US');
    expect($branch->currency_code)->toBe('USD');
    expect($branch->currency_symbol)->toBe('$');
    expect($branch->tax_rate)->toBe('7.50');
    expect($branch->is_active)->toBeTrue();
    expect($branch->is_primary)->toBeTrue();
});

test('branch can have users', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();

    $branch->users()->attach($user);

    expect($branch->users->count())->toBe(1);
    expect($branch->users->first()->id)->toBe($user->id);
});

test('branch scope active filters correctly', function () {
    Branch::factory()->count(3)->active()->create();
    Branch::factory()->count(2)->inactive()->create();

    expect(Branch::active()->count())->toBe(3);
    expect(Branch::query()->count())->toBe(5);
});

test('branch scope primary filters correctly', function () {
    Branch::factory()->count(2)->primary()->create();
    Branch::factory()->count(3)->create();

    expect(Branch::primary()->count())->toBe(2);
});

test('branch scope for country filters correctly', function () {
    Branch::factory()->count(3)->create(['country' => 'US']);
    Branch::factory()->count(2)->create(['country' => 'CA']);

    expect(Branch::forCountry('US')->count())->toBe(3);
    expect(Branch::forCountry('CA')->count())->toBe(2);
});

test('branch uses soft deletes', function () {
    $branch = Branch::factory()->create();

    $branch->delete();

    expect(Branch::withTrashed()->count())->toBe(1);
    expect(Branch::count())->toBe(0);
});

test('branch has correct casts', function () {
    $branch = Branch::factory()->create();

    expect($branch->is_active)->toBeBool();
    expect($branch->is_primary)->toBeBool();
    expect($branch->settings)->toBeNull();
    expect($branch->metadata)->toBeNull();
});
