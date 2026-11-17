<?php

namespace App\Services;

use App\Models\OfflineCommand;
use Illuminate\Support\Str;

class OfflineOverrideService
{
    /** @return array{token: string, expires_at: string} */
    public function issue(OfflineCommand $command): array
    {
        $token = Str::random(48);
        $expiresAt = now()->addMinutes(max(1, config()->integer('offline.override_ttl_minutes', 720)));

        $command->update([
            'override_token_hash' => hash('sha256', $token),
            'override_expires_at' => $expiresAt,
            'override_used_at' => null,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt->toIso8601String()];
    }

    public function consume(OfflineCommand $command, ?string $token): bool
    {
        if (! is_string($token) || $token === '' || $command->override_used_at !== null || $command->override_expires_at?->isPast() !== false || $command->override_token_hash === null) {
            return false;
        }

        if (! hash_equals($command->override_token_hash, hash('sha256', $token))) {
            return false;
        }

        $command->update(['override_used_at' => now()]);

        return true;
    }
}
