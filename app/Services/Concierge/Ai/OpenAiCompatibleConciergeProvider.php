<?php

namespace App\Services\Concierge\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI-compatible chat completions provider. Serves OpenAI,
 * OpenRouter and Google's Gemini (OpenAI-compatible endpoint) from a
 * single client so the concierge driver stays provider-swappable.
 */
class OpenAiCompatibleConciergeProvider implements ConciergeAiProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function complete(string $systemPrompt, array $messages, ?array $tools = null, ?string $model = null): ConciergeAiResponse
    {
        $response = $this->client($model)->post('/chat/completions', [
            'model' => $model ?? $this->model,
            'messages' => $this->messages($systemPrompt, $messages),
            'tools' => $tools ?? [],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Concierge AI provider request failed.');
        }

        $message = $response->json('choices.0.message');
        $message = is_array($message) ? $message : [];
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';

        $toolCalls = $message['tool_calls'] ?? [];

        return new ConciergeAiResponse($text, $this->toolCalls(is_array($toolCalls) ? $toolCalls : []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function messages(string $systemPrompt, array $messages): array
    {
        $out = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($messages as $message) {
            $role = is_string($message['role'] ?? null) ? $message['role'] : 'user';
            $content = is_string($message['content'] ?? null) ? $message['content'] : '';

            $out[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    private function toolCalls(array $toolCalls): array
    {
        $out = [];

        foreach ($toolCalls as $toolCall) {
            if (! is_array($toolCall) || ($toolCall['type'] ?? null) !== 'function') {
                continue;
            }

            $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
            $arguments = $function['arguments'] ?? '{}';
            $decoded = json_decode(is_string($arguments) ? $arguments : '{}', true);

            $out[] = [
                'name' => is_string($function['name'] ?? null) ? $function['name'] : 'unknown',
                'arguments' => is_array($decoded) ? $decoded : [],
            ];
        }

        return $out;
    }

    private function client(?string $model): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->connectTimeout(5)->timeout(15);

        if ($this->apiKey !== null) {
            $client = $client->withToken($this->apiKey);
        }

        return $client;
    }
}
