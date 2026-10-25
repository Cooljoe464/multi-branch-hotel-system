<?php

namespace App\Services\Security;

use App\Models\Branch;
use App\Models\BranchSecret;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;

/**
 * Single point of entry for vendor credentials. Values are sealed with
 * Laravel Crypt on write and only ever returned decrypted to trusted
 * services — never to views.
 *
 * Scope is the branch when the vendor is configured per property
 * (FIRS, accounting OAuth, lock gateways) and null for platform-wide
 * vendor accounts (OCR). Rotation is deliberately manual: an admin
 * triggers rotate() with a freshly issued credential; nothing in the
 * scheduler ever rewrites a secret on its own.
 */
class SecretManager
{
    public const KEY_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    public function get(?Branch $branch, string $key): ?string
    {
        $this->assertKey($key);

        $secret = $this->query($branch, $key)->first();

        if (! $secret instanceof BranchSecret) {
            return null;
        }

        return $this->decrypt($secret->value);
    }

    public function has(?Branch $branch, string $key): bool
    {
        return $this->get($branch, $key) !== null;
    }

    /**
     * Store or replace a credential without touching rotation
     * metadata (first-time provisioning, corrected typo, …).
     */
    public function set(?Branch $branch, string $key, string $value): BranchSecret
    {
        $this->assertKey($key);

        $secret = $this->query($branch, $key)->first()
            ?? new BranchSecret([
                'key' => $key,
            ]);

        $secret->branch_id = $branch?->id;
        $secret->value = $this->encrypt($value);
        $secret->save();

        return $secret;
    }

    /**
     * Manual rotation only — records when the credential last changed
     * so operators can audit vendor-side rollouts.
     */
    public function rotate(?Branch $branch, string $key, string $value): BranchSecret
    {
        $secret = $this->set($branch, $key, $value);
        $secret->forceFill(['rotated_at' => now()])->save();

        return $secret;
    }

    public function forget(?Branch $branch, string $key): void
    {
        $this->assertKey($key);

        $this->query($branch, $key)->delete();
    }

    /**
     * Metadata for admin screens: which secrets exist and when they
     * were last rotated, without ever exposing the value.
     *
     * @return list<array{key: string, scope: string, rotated_at: string|null, updated_at: string|null}>
     */
    public function inventory(?Branch $branch): array
    {
        $secrets = $this->query($branch)
            ->orderBy('key')
            ->get(['branch_id', 'key', 'rotated_at', 'updated_at']);

        $inventory = [];

        foreach ($secrets as $secret) {
            $inventory[] = [
                'key' => $secret->key,
                'scope' => $secret->branch_id !== null ? 'branch' : 'platform',
                'rotated_at' => $secret->rotated_at?->toIso8601String(),
                'updated_at' => $secret->updated_at?->toIso8601String(),
            ];
        }

        return $inventory;
    }

    /**
     * @param  string|null  $key  Null fetches the whole scope.
     * @return Builder<BranchSecret>
     */
    private function query(?Branch $branch, ?string $key = null): Builder
    {
        $query = BranchSecret::query();

        $query = $branch instanceof Branch
            ? $query->where('branch_id', $branch->id)
            : $query->whereNull('branch_id');

        if ($key !== null) {
            $query->where('key', $key);
        }

        return $query;
    }

    private function assertKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException("Invalid secret key [{$key}].");
        }
    }

    private function encrypt(string $value): string
    {
        return 'enc:'.Crypt::encryptString($value);
    }

    private function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $payload = str_starts_with($value, 'enc:') ? substr($value, 4) : $value;

        try {
            $decrypted = Crypt::decryptString($payload);
        } catch (\Throwable) {
            return null;
        }

        return $decrypted === '' ? null : $decrypted;
    }
}
