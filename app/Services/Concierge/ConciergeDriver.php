<?php

namespace App\Services\Concierge;

use App\Models\ConciergeThread;

interface ConciergeDriver
{
    /**
     * Classify one guest turn against branch knowledge. Drivers must
     * never send messages or write business tables.
     */
    public function interpret(ConciergeThread $thread, string $body): ConciergeIntent;
}
