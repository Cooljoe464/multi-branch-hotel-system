<?php

namespace App\Services\Concierge\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic Messages API concierge provider.
 */
class AnthropicConciergeProvider implements ConciergeAiProvider
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const VERSION = '2023-06-01';

    private const MAX_TOKENS = 1024;

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function complete(string $systemPrompt, array $messages, ?array $tools = null, ?string $model = null): ConciergeAiResponse
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'x-api-key' => (string) ($this->apiKey ?? ''),
                'anthropic-version' => self::VERSION,
                'content-type' => 'application/json',
            ])
            ->post('/v1/messages', [
                'model' => $model ?? $this->model,
                'max_tokens' => self::MAX_TOKENS,
                'system' => $systemPrompt,
                'messages' => $this->anthropicMessages($messages),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Concierge AI provider request failed.');
        }

        $blocks = $response->json('content');
        $text = '';
        $toolCalls = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }

            if (($block['type'] ?? null) === 'tool_use') {
                $arguments = json_decode((string) ($block['input'] ?? '{}'), true);

                $toolCalls[] = [
                    'name' => is_string($block['name'] ?? null) ? $block['name'] : 'unknown',
                    'arguments' => is_array($arguments) ? $arguments : [],
                ];
            }
        }

        return new ConciergeAiResponse(trim($text), $toolCalls);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function anthropicMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $role = is_string($message['role'] ?? null) ? $message['role'] : 'user';
            $content = is_string($message['content'] ?? null) ? $message['content'] : '';

            $out[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => [['type' => 'text', 'text' => $content]],
            ];
        }

        return $out;
    }
}
