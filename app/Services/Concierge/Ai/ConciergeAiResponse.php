<?php

namespace App\Services\Concierge\Ai;

/**
 * The model's completion for one concierge turn: the assistant text
 * plus any structured tool calls emitted by the provider.
 *
 * @param  array<int, array<string, mixed>>  $toolCalls
 */
final readonly class ConciergeAiResponse
{
    public function __construct(
        public string $text,
        public array $toolCalls = [],
    ) {}
}
