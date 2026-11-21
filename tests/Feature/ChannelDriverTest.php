<?php

use App\Models\Branch;
use App\Models\ChannelProviderModel;
use App\Services\Channels\ChannexDriver;
use App\Services\Channels\CloudbedsDriver;
use App\Services\Channels\RateGainDriver;
use App\Services\Channels\SiteMinderDriver;
use App\Services\ChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->service = new ChannelService;
});

it('resolves the correct channel driver from provider settings', function () {
    $smProvider = ChannelProviderModel::create([
        'branch_id' => $this->branch->id,
        'provider' => 'siteminder',
        'settings' => ['driver' => 'siteminder'],
    ]);
    expect($this->service->driverFor($smProvider, 'booking.com'))->toBeInstanceOf(SiteMinderDriver::class);

    $rgProvider = ChannelProviderModel::create([
        'branch_id' => $this->branch->id,
        'provider' => 'rategain',
        'settings' => ['driver' => 'rategain'],
    ]);
    expect($this->service->driverFor($rgProvider, 'expedia'))->toBeInstanceOf(RateGainDriver::class);

    $cbProvider = ChannelProviderModel::create([
        'branch_id' => $this->branch->id,
        'provider' => 'cloudbeds',
        'settings' => ['driver' => 'cloudbeds'],
    ]);
    expect($this->service->driverFor($cbProvider, 'airbnb'))->toBeInstanceOf(CloudbedsDriver::class);

    $chxProvider = ChannelProviderModel::create([
        'branch_id' => $this->branch->id,
        'provider' => 'channex',
        'settings' => ['driver' => 'channex'],
    ]);
    expect($this->service->driverFor($chxProvider, 'agoda'))->toBeInstanceOf(ChannexDriver::class);
});

it('pushes ARI through SiteMinder driver successfully', function () {
    Http::fake([
        'https://api.siteminder.com/pmsxchange/v1/ari/updates' => Http::response([
            'transaction_id' => 'SM-TX-98765',
            'status' => 'acknowledged',
        ], 200),
    ]);

    $driver = new SiteMinderDriver;
    $res = $driver->pushAri('sm-idem-1', [
        'channel_hotel_code' => 'HOTEL-IKOYI',
        'updates' => [
            ['rate_plan' => 'BAR', 'availability' => 5],
        ],
    ]);

    expect($res['ok'])->toBeTrue()
        ->and($res['external_ref'])->toBe('SM-TX-98765');
});

it('pushes ARI through Channex driver and parses errors', function () {
    Http::fake([
        'https://api.channex.io/api/v1/restrictions' => Http::response([
            'errors' => [
                ['title' => 'Room rate plan ID invalid or unmapped'],
            ],
        ], 422),
    ]);

    $driver = new ChannexDriver;
    $res = $driver->pushAri('chx-idem-1', ['restrictions' => []]);

    expect($res['ok'])->toBeFalse()
        ->and($res['error'])->toContain('Room rate plan ID invalid');
});

it('fetches inventory through Cloudbeds driver', function () {
    Http::fake([
        'https://api.cloudbeds.com/api/v1.2/getInventory*' => Http::response([
            'data' => [
                ['stay_date' => '2026-12-01', 'channel_room_code' => 'DLX', 'sellable' => 8, 'rate_minor' => 4500000],
            ],
        ], 200),
    ]);

    $driver = new CloudbedsDriver;
    $inv = $driver->fetchInventory($this->branch, '2026-12-01', '2026-12-02');

    expect($inv)->toHaveCount(1)
        ->and($inv[0]['channel_room_code'])->toBe('DLX')
        ->and($inv[0]['sellable'])->toBe(8);
});
