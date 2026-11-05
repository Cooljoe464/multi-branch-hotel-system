<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
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

    public function provider(): ConciergeAiProvider
    {
        $name = config('services.concierge_ai.provider');
        $name = is_string($name) && $name !== '' ? $name : 'openai';

        $class = self::PROVIDERS[$name] ?? null;

        if ($class === null) {
            throw new AvailabilityException(
                'CONCIERGE_AI_UNCONFIGURED',
                "Unknown concierge AI provider [{$name}]. Known providers: ".implode(', ', array_keys(self::PROVIDERS)).'.',
            );
        }

        $group = $this->group($name);

        return new $class(
            (string) ($group['base_url'] ?? self::DEFAULT_BASE_URLS[$name]),
            is_string($group['api_key'] ?? null) ? $group['api_key'] : null,
            is_string($group['model'] ?? null) ? $group['model'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $name): array
    {
        $config = config('services.concierge_ai', []);
        $providers = is_array($config) ? ($config['providers'] ?? []) : [];
        $group = is_array($providers) ? ($providers[$name] ?? []) : [];

        return is_array($group) ? $group : [];
    }
}
