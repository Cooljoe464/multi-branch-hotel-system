<?php

return [
    'requests_per_minute' => (int) env('PUBLIC_API_REQUESTS_PER_MINUTE', 120),
    'hold_ttl_minutes' => (int) env('PUBLIC_API_HOLD_TTL_MINUTES', 10),
];
