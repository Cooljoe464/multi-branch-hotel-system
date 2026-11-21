<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds deprecation and sunset headers for deprecated api routes', function () {
    config([
        'public_api.lifecycle.deprecated' => [
            'api.webhooks.paystack' => [
                'sunset_at' => '2027-01-01T00:00:00Z',
                'message' => 'Migrate to the connection-scoped webhook endpoint.',
            ],
        ],
    ]);

    $this->postJson('/api/webhooks/paystack', [])->assertHeader('Deprecation', 'true')->assertHeader('Sunset', '2027-01-01T00:00:00Z');
});

it('omits lifecycle headers when no api route is deprecated', function () {
    config(['public_api.lifecycle.deprecated' => []]);

    $this->postJson('/api/webhooks/paystack', [])->assertHeaderMissing('Deprecation')->assertHeaderMissing('Sunset');
});
