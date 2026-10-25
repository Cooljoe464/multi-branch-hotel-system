<?php

namespace App\Services\Concierge;

use App\Models\ConciergeKbEntry;

/**
 * The concierge's decision for one guest turn. Intents are explicit so
 * the orchestrator (not the driver) owns side effects: the driver only
 * classifies and grounds, ConciergeService executes tools and sends.
 */
final readonly class ConciergeIntent
{
    public const FAQ = 'faq';

    public const RATE = 'rate';

    public const BOOKING = 'booking';

    public const LATE_CHECKOUT = 'late_checkout';

    public const MAINTENANCE = 'maintenance';

    public const CONFIRM = 'confirm';

    public const DECLINE = 'decline';

    public const HUMAN = 'human';

    public const GREETING = 'greeting';

    public const UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public string $intent,
        public float $confidence = 0.0,
        public ?ConciergeKbEntry $entry = null,
        public array $args = [],
        public ?string $handoffReason = null,
    ) {}

    public function needsHuman(): bool
    {
        return $this->handoffReason !== null;
    }
}
