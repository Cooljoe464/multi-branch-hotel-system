<?php

use App\Models\Branch;
use App\Models\EdgeAgent;
use App\Services\EdgeAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
});

it('issues an agent and shows the token once without storing it', function () {
    $response = $this->actingAs($this->user)
        ->post('/settings/edge-agents', ['name' => 'Front office'])
        ->assertRedirect();

    $token = session('token');
    $agent = EdgeAgent::where('branch_id', $this->branch->id)->sole();

    expect($token)->toStartWith('hos_edge_')
        ->and($agent->token_hash)->toBe(hash('sha256', $token))
        ->and($agent->token_hash)->not->toBe($token);

    $this->actingAs($this->user)
        ->get('/settings/edge-agents')
        ->assertInertia(fn ($page) => $page
            ->component('settings/EdgeAgents')
            ->has('agents', 1)
            ->where('agents.0.is_stale', true)
            ->missing('agents.0.token_hash'))
        ->assertDontSee($token);
});

it('requires a name', function () {
    $this->actingAs($this->user)
        ->post('/settings/edge-agents', ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('revokes an agent so its token stops resolving', function () {
    $this->actingAs($this->user)->post('/settings/edge-agents', ['name' => 'Node']);
    $token = session('token');
    $agent = EdgeAgent::sole();

    $this->actingAs($this->user)->post("/settings/edge-agents/{$agent->id}/revoke")->assertRedirect();

    expect($agent->fresh()->status)->toBe(EdgeAgent::STATUS_REVOKED)
        ->and(app(EdgeAgentService::class)->resolve($token))->toBeNull();
});

it('does not let a user revoke another property agent', function () {
    $other = EdgeAgent::factory()->create();

    $this->actingAs($this->user)
        ->post("/settings/edge-agents/{$other->id}/revoke")
        ->assertForbidden();

    expect($other->fresh()->status)->toBe(EdgeAgent::STATUS_ACTIVE);
});
