<?php

namespace App\Services\Scim;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * SCIM 2.0 user provisioning for corporate directories into the
 * property management users. Directory identities live behind the SCIM
 * issuer namespace so the same human can later be linked to an SSO
 * identity without colliding with local accounts.
 */
class ScimService
{
    private const ISSUER = 'scim';

    /**
     * @return Collection<int, User>
     */
    public function listForTenant(int $tenantId): Collection
    {
        return User::query()
            ->where('sso_issuer', self::ISSUER)
            ->whereHas('branches', fn ($query) => $query->where('branches.tenant_id', $tenantId))
            ->orderBy('id')
            ->get();
    }

    public function create(string $subject, string $userName, array $name, bool $active): User
    {
        $email = strtolower(trim($userName));
        $user = User::query()
            ->where('sso_issuer', self::ISSUER)
            ->where('sso_subject', $subject)
            ->first();

        if ($user === null) {
            $user = User::create([
                'name' => trim(((string) ($name['given_name'] ?? '')).' '.((string) ($name['family_name'] ?? ''))),
                'email' => $email,
                'password' => str()->random(32),
                'email_verified_at' => now(),
                'sso_issuer' => self::ISSUER,
                'sso_subject' => $subject,
            ]);
        }

        if ($user->email !== $email) {
            $user->forceFill(['email' => $email])->save();
        }

        if ($active) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user->fresh();
    }

    public function update(User $user, string $subject, string $userName, array $name, bool $active): User
    {
        if ($user->sso_subject !== $subject) {
            return $user;
        }

        $user->forceFill([
            'email' => strtolower(trim($userName)),
            'name' => trim(((string) ($name['given_name'] ?? '')).' '.((string) ($name['family_name'] ?? ''))),
        ])->save();

        if (! $active) {
            $user->delete();

            return $user->fresh();
        }

        return $user->fresh();
    }

    public function revoke(User $user): User
    {
        $user->delete();

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function toResource(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'userName' => $user->email,
            'name' => [
                'givenName' => $user->name,
                'familyName' => '',
            ],
            'emails' => [
                [
                    'value' => $user->email,
                    'primary' => true,
                    'type' => 'work',
                ],
            ],
            'meta' => [
                'resourceType' => 'User',
                'createdAt' => $user->created_at?->toIso8601String(),
            ],
        ];
    }
}
