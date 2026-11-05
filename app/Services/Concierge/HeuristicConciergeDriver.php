<?php

namespace App\Services\Concierge;

use App\Models\ConciergeKbEntry;
use App\Models\ConciergeThread;

/**
 * Deterministic, dependency-free concierge driver. It grounds FAQ
 * answers in the branch KB and classifies the handful of intents the
 * property actually automates. A model-backed driver can replace it by
 * implementing ConciergeDriver without touching the orchestrator.
 */
class HeuristicConciergeDriver implements ConciergeDriver
{
    private const STOPWORDS = [
        'the', 'a', 'an', 'is', 'are', 'do', 'does', 'can', 'i', 'we', 'you',
        'my', 'me', 'to', 'of', 'for', 'at', 'in', 'on', 'and', 'or', 'please',
        'what', 'whats', 'how', 'when', 'where', 'it', 'this', 'that', 'be',
        'have', 'has', 'with', 'your', 'our', 'there', 'any', 'about', 'tell',
    ];

    private const HUMAN_WORDS = ['human', 'agent', 'reception', 'front desk', 'staff', 'talk to someone', 'real person', 'supervisor'];

    private const COMPLAINT_WORDS = ['angry', 'terrible', 'unacceptable', 'complaint', 'refund', 'disappointed', 'rude', 'disgusting', 'furious', 'manager'];

    private const MAINTENANCE_WORDS = ['leak', 'leaking', 'broken', 'not working', 'no water', 'no hot water', 'air conditioner', 'ac ', 'dirty', 'smell', 'noise', 'noisy', 'clogged', 'power outage', 'wifi down', 'wifi not working'];

    private const LATE_CHECKOUT_WORDS = ['late checkout', 'late check-out', 'stay longer', 'extend checkout', 'later checkout'];

    private const RATE_WORDS = ['price', 'rate', 'cost', 'how much', 'total', 'bill', 'charge'];

    private const BOOKING_WORDS = ['booking', 'reservation', 'confirmation', 'my stay', 'check in date', 'checkout date'];

    private const GREETING_WORDS = ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'greetings'];

    public function interpret(ConciergeThread $thread, string $body, array $history = []): ConciergeIntent
    {
        $normalized = $this->normalize($body);

        if ($normalized === '') {
            return new ConciergeIntent(ConciergeIntent::UNKNOWN, 0.0, handoffReason: 'empty_message');
        }

        if ($this->containsAny($normalized, self::HUMAN_WORDS)) {
            return new ConciergeIntent(ConciergeIntent::HUMAN, 1.0, handoffReason: 'guest_requested_human');
        }

        if ($this->containsAny($normalized, self::COMPLAINT_WORDS)) {
            return new ConciergeIntent(ConciergeIntent::HUMAN, 1.0, handoffReason: 'complaint_sentiment');
        }

        if ($this->isConfirmation($normalized)) {
            return new ConciergeIntent(ConciergeIntent::CONFIRM, 1.0);
        }

        if ($this->isDecline($normalized)) {
            return new ConciergeIntent(ConciergeIntent::DECLINE, 1.0);
        }

        if ($this->containsAny($normalized, self::MAINTENANCE_WORDS)) {
            return new ConciergeIntent(ConciergeIntent::MAINTENANCE, 0.8, args: ['note' => $body]);
        }

        if ($this->containsAny($normalized, self::LATE_CHECKOUT_WORDS)) {
            return new ConciergeIntent(ConciergeIntent::LATE_CHECKOUT, 0.9);
        }

        if ($this->containsAny($normalized, self::RATE_WORDS)) {
            return new ConciergeIntent(ConciergeIntent::RATE, 0.7);
        }

        if ($this->containsAny($normalized, self::BOOKING_WORDS)) {
            return new ConciergeIntent(ConciergeIntent::BOOKING, 0.7);
        }

        $entry = $this->matchKb($thread, $normalized);

        if ($entry !== null) {
            return new ConciergeIntent(ConciergeIntent::FAQ, 0.8, $entry);
        }

        if ($this->containsAny($normalized, self::GREETING_WORDS)) {
            return new ConciergeIntent(ConciergeIntent::GREETING, 0.5);
        }

        return new ConciergeIntent(ConciergeIntent::UNKNOWN, 0.0, handoffReason: 'low_confidence');
    }

    private function matchKb(ConciergeThread $thread, string $normalized): ?ConciergeKbEntry
    {
        $tokens = $this->tokens($normalized);

        if ($tokens === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach (ConciergeKbEntry::forBranch($thread->branch_id)->active()->orderBy('id')->get() as $entry) {
            $haystack = $this->normalize(implode(' ', [
                $entry->topic,
                (string) $entry->question,
                implode(' ', is_array($entry->keywords) ? array_filter($entry->keywords, 'is_string') : []),
            ]));

            $score = 0;

            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $best = $entry;
                $bestScore = $score;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    private function isConfirmation(string $normalized): bool
    {
        return preg_match('/^(yes|yeah|yep|ok|okay|sure|confirm|confirmed|please do|do it)[.! ]*$/', $normalized) === 1;
    }

    private function isDecline(string $normalized): bool
    {
        return preg_match('/^(no|nope|nah|not now|cancel|do not|don.t)[.! ]*$/', $normalized) === 1;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalized): array
    {
        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if ($part === '' || in_array($part, self::STOPWORDS, true) || mb_strlen($part) < 3) {
                continue;
            }

            $tokens[$part] = true;
        }

        return array_keys($tokens);
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)) ?? '');
    }
}
