<?php

return [
    'override_ttl_minutes' => (int) env('OFFLINE_OVERRIDE_TTL_MINUTES', 720),
    'max_provisional_check_ins_per_outage' => (int) env('OFFLINE_MAX_CHECK_INS_PER_OUTAGE', 20),
    'storage_budget_bytes' => (int) env('OFFLINE_STORAGE_BUDGET_BYTES', 50 * 1024 * 1024),
];
