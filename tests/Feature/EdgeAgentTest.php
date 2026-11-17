<?php

use App\Models\Branch;
use App\Services\EdgeAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('AC-055 accepts the current edge protocol and two previous minor versions', function () {
    config(['edge.current_version' => '1.4', 'edge.previous_minor_versions' => 2]);
    $service = app(EdgeAgentService::class);

    expect($service->compatibility('1.4')['accepted'])->toBeTrue()
        ->and($service->compatibility('1.2')['accepted'])->toBeTrue()
        ->and($service->compatibility('1.1')['accepted'])->toBeFalse()
        ->and($service->compatibility('2.0')['accepted'])->toBeFalse();
});

test('AC-055 heartbeat refuses an edge agent three minor versions behind', function () {
    config(['edge.current_version' => '1.4', 'edge.previous_minor_versions' => 2]);
    $branch = Branch::factory()->create();
    $issued = app(EdgeAgentService::class)->issue($branch, 'Lobby edge', '1.4');

    $this->withToken($issued['token'])
        ->withHeader('X-Edge-Protocol', '1.2')
        ->postJson('/api/v1/edge/heartbeat')
        ->assertOk()
        ->assertJsonPath('data.accepted', true);

    expect($issued['agent']->fresh()->last_seen_at)->not->toBeNull();

    $this->withToken($issued['token'])
        ->withHeader('X-Edge-Protocol', '1.1')
        ->postJson('/api/v1/edge/heartbeat')
        ->assertStatus(426)
        ->assertJsonPath('current_version', '1.4')
        ->assertJsonPath('supported_versions', ['1.4', '1.3', '1.2']);
});
