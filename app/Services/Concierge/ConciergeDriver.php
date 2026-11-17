<?php

namespace App\Services\Concierge;

use App\Models\ConciergeThread;

interface ConciergeDriver
{
    /**
     * Classify one guest turn against branch knowledge. Drivers must
     * never send messages or write business tables.
     */
    /** @param array<int, array<string, mixed>> $history */
    public function interpret(ConciergeThread $thread, string $body, array $history = []): ConciergeIntent;
}
