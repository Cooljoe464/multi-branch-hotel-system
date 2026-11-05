<?php

namespace App\Services\Concierge\Ai;

/**
 * Seam for the concierge's language model. Implementations never send
 * WhatsApp messages or write business tables; the driver maps the
 * completion onto a ConciergeIntent.
 */
interface ConciergeAiProvider
{
    /**
     * Complete one concierge turn against the branch-grounded context.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $tools
     */
    public function complete(string $systemPrompt, array $messages, ?array $tools = null, ?string $model = null): ConciergeAiResponse;
}
