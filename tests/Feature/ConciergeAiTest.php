<?php

use App\Services\Concierge\Ai\AnthropicConciergeProvider;
use App\Services\Concierge\Ai\ConciergeAiResponse;
use App\Services\Concierge\Ai\OpenAiCompatibleConciergeProvider;
use Illuminate\Support\Facades\Http;

it('completes an OpenAI-compatible chat completion and returns the assistant text', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"intent":"faq","confidence":0.9}']],
            ],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleConciergeProvider('https://api.openai.com/v1', 'test-key', 'gpt-4o-mini');

    $response = $provider->complete('You are the concierge.', [['role' => 'user', 'content' => 'What is the wifi password?']]);

    expect($response)->toBeInstanceOf(ConciergeAiResponse::class)
        ->and($response->text)->toBe('{"intent":"faq","confidence":0.9}')
        ->and($response->toolCalls)->toBe([]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com/v1/chat/completions'));
});

it('parses tool calls from an OpenAI-compatible chat completion', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'request_late_checkout',
                                    'arguments' => json_encode(['offer_id' => 12, 'fee_minor' => 5000]),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleConciergeProvider('https://openrouter.ai/api/v1', 'test-key', 'openai/gpt-4o-mini');

    $response = $provider->complete('You are the concierge.', [['role' => 'user', 'content' => 'Can I get a late checkout?']], [
        ['type' => 'function', 'function' => ['name' => 'request_late_checkout', 'parameters' => []]],
    ]);

    expect($response->toolCalls)->toBe([
        ['name' => 'request_late_checkout', 'arguments' => ['offer_id' => 12, 'fee_minor' => 5000]],
    ]);
});

it('completes an Anthropic messages request and joins text blocks', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => '{"intent":"greeting","confidence":0.5}'],
                ['type' => 'text', 'text' => 'Hello! How can I help?'],
            ],
        ], 200),
    ]);

    $provider = new AnthropicConciergeProvider('https://api.anthropic.com', 'test-key', 'claude-3-5-haiku-latest');

    $response = $provider->complete('You are the concierge.', [['role' => 'user', 'content' => 'Hello']]);

    expect($response->text)->toBe('{"intent":"greeting","confidence":0.5}Hello! How can I help?')
        ->and($response->toolCalls)->toBe([]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com/v1/messages'));
});
