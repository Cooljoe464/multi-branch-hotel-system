<?php

use App\Http\Resources\GuestResource;
use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serializes guest attributes correctly', function () {
    $guest = Guest::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '+1234567890',
        'vip_status' => 'gold',
        'total_stays' => 12,
        'total_nights' => 36,
        'total_spent' => 600000,
        'currency_code' => 'USD',
        'preferred_language' => 'en',
        'preferred_currency' => 'USD',
    ]);

    $resource = new GuestResource($guest);
    $array = $resource->toArray(request());

    expect($array['id'])->toBe($guest->id)
        ->and($array['first_name'])->toBe('John')
        ->and($array['last_name'])->toBe('Doe')
        ->and($array['full_name'])->toBe('John Doe')
        ->and($array['email'])->toBe('john@example.com')
        ->and($array['phone'])->toBe('+1234567890')
        ->and($array['vip_status'])->toBe('gold')
        ->and($array['total_stays'])->toBe(12)
        ->and($array['total_nights'])->toBe(36)
        ->and($array['total_spent'])->toBe(600000)
        ->and($array['currency_code'])->toBe('USD')
        ->and($array['preferred_language'])->toBe('en')
        ->and($array['preferred_currency'])->toBe('USD');
});

it('serializes full_name accessor correctly', function () {
    $guest = Guest::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
    ]);

    $resource = new GuestResource($guest);
    $array = $resource->toArray(request());

    expect($array['full_name'])->toBe('Jane Smith');
});

it('serializes nullable fields correctly', function () {
    $guest = Guest::factory()->create([
        'phone' => null,
        'date_of_birth' => null,
        'nationality' => null,
        'company' => null,
        'dietary_restrictions' => null,
        'special_notes' => null,
        'last_stayed_at' => null,
    ]);

    $resource = new GuestResource($guest);
    $array = $resource->toArray(request());

    expect($array['phone'])->toBeNull()
        ->and($array['date_of_birth'])->toBeNull()
        ->and($array['nationality'])->toBeNull()
        ->and($array['company'])->toBeNull()
        ->and($array['dietary_restrictions'])->toBeNull()
        ->and($array['special_notes'])->toBeNull()
        ->and($array['last_stayed_at'])->toBeNull();
});

it('serializes date fields as strings', function () {
    $guest = Guest::factory()->create([
        'date_of_birth' => '1990-05-15',
    ]);

    $resource = new GuestResource($guest);
    $array = $resource->toArray(request());

    expect($array['date_of_birth'])->toBe('1990-05-15');
});

it('serializes all vip statuses', function () {
    foreach (['none', 'silver', 'gold', 'platinum', 'diamond'] as $status) {
        $guest = Guest::factory()->create(['vip_status' => $status]);
        $resource = new GuestResource($guest);
        $array = $resource->toArray(request());

        expect($array['vip_status'])->toBe($status);
    }
});
