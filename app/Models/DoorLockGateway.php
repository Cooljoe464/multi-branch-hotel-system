<?php

namespace App\Models;

use App\Services\Security\SecretManager;
use Database\Factories\DoorLockGatewayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $provider
 * @property string $api_base_url
 * @property string $api_key
 * @property string|null $api_secret
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'provider',
    'api_base_url',
    'api_key',
    'api_secret',
    'is_active',
    'settings',
])]
class DoorLockGateway extends Model
{
    /** @use HasFactory<DoorLockGatewayFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<DoorLockAuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(DoorLockAuditLog::class, 'gateway_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Credential read path for every lock provider: the branch secret
     * store wins, then the legacy plaintext column — so gateways
     * provisioned before the secret store keep working untouched.
     */
    public function apiKey(): ?string
    {
        return $this->storedSecret('api_key')
            ?? $this->legacy($this->api_key);
    }

    public function apiSecret(): ?string
    {
        return $this->storedSecret('api_secret')
            ?? $this->legacy($this->api_secret);
    }

    /**
     * OAuth client id (dormakaba Community style APIs). Non-secret, so
     * it may also ride in settings.client_id.
     */
    public function clientId(): ?string
    {
        $settings = $this->settings;
        $fromSettings = is_array($settings) ? ($settings['client_id'] ?? null) : null;

        return $this->storedSecret('client_id')
            ?? (is_string($fromSettings) && $fromSettings !== '' ? $fromSettings : null);
    }

    /**
     * Provision or replace a credential in the secret store and clear
     * the legacy column, leaving exactly one source of truth.
     */
    public function storeApiKey(string $value, bool $rotate = false): void
    {
        $this->storeSecret('api_key', $value, $rotate);
    }

    public function storeApiSecret(string $value, bool $rotate = false): void
    {
        $this->storeSecret('api_secret', $value, $rotate);
    }

    private function storeSecret(string $name, string $value, bool $rotate): void
    {
        $secrets = app(SecretManager::class);

        if ($rotate) {
            $secrets->rotate($this->branch, $this->secretKey($name), $value);
        } else {
            $secrets->set($this->branch, $this->secretKey($name), $value);
        }

        if ($name === 'api_key' || $name === 'api_secret') {
            $this->forceFill([$name => ''])->save();
        }
    }

    private function storedSecret(string $name): ?string
    {
        return app(SecretManager::class)->get($this->branch, $this->secretKey($name));
    }

    private function secretKey(string $name): string
    {
        return 'door_lock.'.$this->id.'.'.$name;
    }

    private function legacy(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
