<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\EdgeAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EdgeAgentService
{
    /** @return array{agent: EdgeAgent, token: string} */
    public function issue(Branch $branch, string $name, string $protocolVersion): array
    {
        $token = 'hos_edge_'.Str::random(64);
        $agent = EdgeAgent::create([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'name' => $name,
            'agent_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'protocol_version' => $protocolVersion,
            'status' => EdgeAgent::STATUS_ACTIVE,
        ]);

        return ['agent' => $agent, 'token' => $token];
    }

    public function resolve(string $token): ?EdgeAgent
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash): ?EdgeAgent {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select set_config(?, ?, true)', ['app.edge_agent_hash', $hash]);
            }

            return EdgeAgent::query()->where('token_hash', $hash)->where('status', EdgeAgent::STATUS_ACTIVE)->first();
        });
    }

    /** @return array{accepted: bool, reason: string|null, supported: list<string>} */
    public function compatibility(string $version): array
    {
        $current = config()->string('edge.current_version');
        $parts = explode('.', $current);
        $major = (int) $parts[0];
        $minor = (int) ($parts[1] ?? -1);
        $supported = [];

        for ($offset = 0; $offset <= config()->integer('edge.previous_minor_versions'); $offset++) {
            $supported[] = "{$major}.".($minor - $offset);
        }

        $accepted = in_array($version, $supported, true);

        return [
            'accepted' => $accepted,
            'reason' => $accepted ? null : "Unsupported edge protocol {$version}; supported versions: ".implode(', ', $supported).'.',
            'supported' => $supported,
        ];
    }

    /** @return array{accepted: bool, reason: string|null, supported: list<string>, agent_id: string, last_seen_at: string|null} */
    public function heartbeat(EdgeAgent $agent, string $version): array
    {
        $compatibility = $this->compatibility($version);

        if ($compatibility['accepted']) {
            $agent->update(['protocol_version' => $version, 'last_seen_at' => now()]);
        }

        return $compatibility + ['agent_id' => $agent->agent_id, 'last_seen_at' => $agent->fresh()?->last_seen_at?->toIso8601String()];
    }
}
