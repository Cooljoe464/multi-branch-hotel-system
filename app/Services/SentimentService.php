<?php

namespace App\Services;

/**
 * Review sentiment scoring. Heuristic lexicon now; the Phase 6 model
 * hook replaces score() without touching callers. Scores run 0..1
 * (1 = delighted). One-star energy with a charge dispute feeds audit
 * flags downstream via the survey status, not from here.
 */
class SentimentService
{
    private const POSITIVE = [
        'excellent', 'amazing', 'wonderful', 'perfect', 'great', 'lovely',
        'fantastic', 'outstanding', 'delightful', 'comfortable', 'clean',
        'friendly', 'helpful', 'beautiful', 'enjoyed', 'loved', 'best',
        'spotless', 'spacious', 'relaxing',
    ];

    private const NEGATIVE = [
        'terrible', 'awful', 'horrible', 'dirty', 'rude', 'broken',
        'disgusting', 'worst', 'nightmare', 'filthy', 'stained', 'noisy',
        'cold', 'smelly', 'mold', 'cockroach', 'refund', 'complaint',
        'disappointed', 'poor',
    ];

    public function score(?string $text, ?int $nps = null): float
    {
        $words = preg_split('/[^a-z]+/', strtolower((string) $text)) ?: [];
        $words = array_filter($words);

        $hits = 0;
        $total = 0;

        foreach ($words as $word) {
            if (in_array($word, self::POSITIVE, true)) {
                $hits++;
                $total++;
            } elseif (in_array($word, self::NEGATIVE, true)) {
                $total++;
            }
        }

        $lexical = $total > 0 ? $hits / $total : 0.5;
        $npsPart = $nps === null ? 0.5 : max(0, min(10, $nps)) / 10;

        return round($lexical * 0.6 + $npsPart * 0.4, 3);
    }
}
