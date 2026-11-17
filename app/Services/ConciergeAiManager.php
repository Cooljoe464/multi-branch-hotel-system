<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Services\Concierge\Ai\AnthropicConciergeProvider;
use App\Services\Concierge\Ai\ConciergeAiProvider;
use App\Services\Concierge\Ai\OpenAiCompatibleConciergeProvider;

/**
 * Resolves the configured concierge AI provider. CONCIERGE_AI_PROVIDER
 * stays 'openai' until a vendor is picked; adding a provider means
 * registering its adapter here and shipping credentials — the driver
 * seam does not change.
 */
class ConciergeAiManager
{
    private const DEFAULT_BASE_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'google' => 'https://generativelanguage.googleapis.com/v1beta/openai',
    ];

    /**
     * @var array<string, class-string<ConciergeAiProvider>>
     */
    private const PROVIDERS = [
        'openai' => OpenAiCompatibleConciergeProvider::class,
        'anthropic' => AnthropicConciergeProvider::class,
        'openrouter' => OpenAiCompatibleConciergeProvider::class,
        'google' => OpenAiCompatibleConciergeProvider::class,
    ];

    public function provider(?Branch $branch = null): ConciergeAiProvider
    {
        $name = $branch instanceof Branch ? $this->branchProvider($branch) : $this->name();
        $group = $this->group($name);
        $baseUrl = is_string($group['base_url'] ?? null) ? $group['base_url'] : self::DEFAULT_BASE_URLS[$name];
        $model = $this->branchModel($branch, $name) ?? $this->groupModel($name);

        $class = self::PROVIDERS[$name] ?? null;

        if ($class === null) {
            throw new AvailabilityException(
                'CONCIERGE_AI_UNCONFIGURED',
                "Unknown concierge AI provider [{$name}]. Known providers: ".implode(', ', array_keys(self::PROVIDERS)).'.',
            );
        }

        return new $class(
            $baseUrl,
            is_string($group['api_key'] ?? null) ? $group['api_key'] : null,
            $model,
        );
    }

    /**
     * Whether the concierge AI for this branch has credentials. An
     * unconfigured branch must not pay a network timeout on every guest
     * turn before falling back, so callers check this first. Branches
     * may override the provider and model via settings.concierge.ai.
     */
    public function isConfigured(?Branch $branch = null): bool
    {
        $name = $branch instanceof Branch ? $this->branchProvider($branch) : $this->name();

        if (! isset(self::PROVIDERS[$name])) {
            return false;
        }

        $apiKey = $this->group($name)['api_key'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    private function name(): string
    {
        $name = config('services.concierge_ai.provider');

        return is_string($name) && $name !== '' ? $name : 'openai';
    }

    /**
     * The AI provider a branch runs, honouring the tenant-level override
     * stored in branch settings. An unknown override falls back to the
     * global default so a bad tenant value can never disable the concierge.
     */
    private function branchProvider(Branch $branch): string
    {
        $override = $this->branchAi($branch)['provider'];

        if (is_string($override) && $override !== '') {
            $name = $override;

            if (isset(self::PROVIDERS[$name])) {
                return $name;
            }
        }

        return $this->name();
    }

    /**
     * The branch-scoped concierge AI override persisted in branch
     * settings under the `concierge.ai` key.
     *
     * @return array{provider: string|null, model: string|null}
     */
    private function branchAi(Branch $branch): array
    {
        $settings = $branch->settings;
        $concierge = is_array($settings['concierge'] ?? null) ? $settings['concierge'] : [];
        $ai = is_array($concierge['ai'] ?? null) ? $concierge['ai'] : [];
        $out = [];

        foreach (['provider', 'model'] as $key) {
            $value = $ai[$key] ?? null;
            $out[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $out;
    }

    private function branchModel(?Branch $branch, string $name): ?string
    {
        if ($branch === null) {
            return null;
        }

        $override = $this->branchAi($branch)['model'];

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return $this->groupModel($name);
    }

    private function groupModel(string $name): ?string
    {
        $group = $this->group($name);
        $model = $group['model'] ?? null;

        return is_string($model) ? $model : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $name): array
    {
        $config = config('services.concierge_ai', []);
        $providers = is_array($config) ? ($config['providers'] ?? []) : [];
        $group = is_array($providers) ? ($providers[$name] ?? []) : [];

        if (! is_array($group)) {
            return [];
        }

        $normalized = [];

        foreach ($group as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
