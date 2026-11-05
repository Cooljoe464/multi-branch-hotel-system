<?php

namespace App\Services\Concierge;

use App\Models\ConciergeKbEntry;
use App\Models\ConciergeThread;
use App\Services\Concierge\Ai\ConciergeAiProvider;
use App\Services\Concierge\Ai\ConciergeAiResponse;

/**
 * Model-backed concierge driver. Classifies each guest turn against the
 * branch knowledge base and returns the grounded ConciergeIntent. On any
 * provider failure it falls back to the deterministic heuristic driver so
 * the guest never stalls without a reply.
 */
class ModelConciergeDriver implements ConciergeDriver
{
    public function __construct(
        private readonly ConciergeAiProvider $provider,
    ) {}

    public function interpret(ConciergeThread $thread, string $body, array $history = []): ConciergeIntent
    {
        try {
            $response = $this->provider->complete(
                $this->systemPrompt($thread),
                [...$history, ['role' => 'user', 'content' => $body]],
            );

            return $this->toIntent($response, $thread);
        } catch (\Throwable) {
            return (new HeuristicConciergeDriver)->interpret($thread, $body, $history);
        }
    }

    private function systemPrompt(ConciergeThread $thread): string
    {
        $entries = ConciergeKbEntry::forBranch($thread->branch_id)->active()->orderBy('id')->get();

        $context = "Branch knowledge base entries:\n";

        foreach ($entries as $entry) {
            $context .= sprintf(
                "- [%d] topic: %s | question: %s | answer: %s | keywords: %s\n",
                $entry->id,
                $entry->topic,
                (string) $entry->question,
                $entry->answer,
                implode(', ', is_array($entry->keywords) ? $entry->keywords : []),
            );
        }

        return 'You are the AI concierge for a hotel branch. Answer the guest from the branch knowledge base. '
            ."Always respond with JSON: {\"intent\": \"<faq|rate|booking|late_checkout|maintenance|confirm|decline|greeting|human|unknown>\", \"confidence\": <float 0-1>, \"kb_entry_id\": <int|null>, \"args\": {<str, mixed>}}.\n"
            ."Money and rates are quoted from the live rate engine — never invent prices. Guests asking about money actions are handed to the front desk. Replies carry the AI disclosure handled by the orchestrator.\n"
            .$context;
    }

    private function toIntent(ConciergeAiResponse $response, ConciergeThread $thread): ConciergeIntent
    {
        $payload = json_decode($response->text, true);
        $intentName = is_string($payload['intent'] ?? null) ? $payload['intent'] : ConciergeIntent::UNKNOWN;
        $confidence = (float) ($payload['confidence'] ?? 0.0);
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $entryId = is_int($payload['kb_entry_id'] ?? null) ? $payload['kb_entry_id'] : null;

        $entry = $entryId !== null
            ? ConciergeKbEntry::query()
                ->where('branch_id', $thread->branch_id)
                ->whereKey($entryId)
                ->first()
            : null;

        return match ($intentName) {
            ConciergeIntent::FAQ => new ConciergeIntent(ConciergeIntent::FAQ, $confidence, $entry, $args),
            ConciergeIntent::RATE => new ConciergeIntent(ConciergeIntent::RATE, $confidence, null, $args),
            ConciergeIntent::BOOKING => new ConciergeIntent(ConciergeIntent::BOOKING, $confidence, null, $args),
            ConciergeIntent::LATE_CHECKOUT => new ConciergeIntent(ConciergeIntent::LATE_CHECKOUT, $confidence, null, $args),
            ConciergeIntent::MAINTENANCE => new ConciergeIntent(ConciergeIntent::MAINTENANCE, $confidence, null, $args),
            ConciergeIntent::CONFIRM => new ConciergeIntent(ConciergeIntent::CONFIRM, $confidence, null, $args),
            ConciergeIntent::DECLINE => new ConciergeIntent(ConciergeIntent::DECLINE, $confidence, null, $args),
            ConciergeIntent::GREETING => new ConciergeIntent(ConciergeIntent::GREETING, $confidence, null, $args),
            default => new ConciergeIntent($intentName, $confidence, null, $args, handoffReason: 'low_confidence'),
        };
    }
}
