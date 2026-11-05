<?php

namespace App\Services\Concierge;

use App\Models\ConciergeKbEntry;
use App\Models\ConciergeMessage;
use App\Models\ConciergeThread;
use App\Services\Concierge\Ai\ConciergeAiProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drafts a reply for a human agent from the branch knowledge base and
 * the recent transcript. The draft is never sent automatically: staff
 * edit and send it through ConciergeService::replyAsAgent(), so the
 * consent, disclosure and handoff rules of the orchestrator still apply.
 */
class ConciergeReplySuggester
{
    private const HISTORY_LIMIT = 20;

    private const MAX_DRAFT_CHARS = 500;

    public function __construct(
        private readonly ConciergeAiProvider $provider,
    ) {}

    /**
     * Return a plain-text draft, or null when the provider is unavailable
     * or returns nothing usable.
     */
    public function suggest(ConciergeThread $thread): ?string
    {
        try {
            $response = $this->provider->complete(
                $this->systemPrompt($thread),
                $this->history($thread),
            );
        } catch (Throwable $exception) {
            Log::warning('Concierge reply suggestion failed.', [
                'thread_id' => $thread->id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $text = trim($response->text);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_DRAFT_CHARS);
    }

    private function systemPrompt(ConciergeThread $thread): string
    {
        $branchName = $thread->branch?->name ?? 'this branch';

        $entries = ConciergeKbEntry::forBranch($thread->branch_id)
            ->active()
            ->orderBy('id')
            ->get();

        $context = "Branch knowledge base entries:\n";

        if ($entries->isEmpty()) {
            $context .= "No knowledge base entries are configured.\n";
        }

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

        return "You draft WhatsApp replies for the front-desk team at {$branchName}. "
            .'A human agent reviews and sends your draft, so write as the hotel team, not as an AI. '
            .'Use plain text only: no markdown, no bullet lists, and no emoji unless the guest used them. '
            ."Keep the draft under 500 characters.\n"
            .'Use only the branch knowledge base below and the transcript. Never invent rates, availability, fees or policies. '
            .'If the guest asks about money, a refund or a booking change, or complains, write a short holding reply and tell them a team member will follow up. '
            ."Do not reveal internal notes or mention the knowledge base.\n"
            .$context;
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function history(ConciergeThread $thread): array
    {
        $messages = ConciergeMessage::query()
            ->where('concierge_thread_id', $thread->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse();

        $history = [];

        foreach ($messages as $message) {
            $content = trim($message->body);

            if ($content === '') {
                continue;
            }

            $history[] = [
                'role' => $message->role === ConciergeMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $history;
    }
}
