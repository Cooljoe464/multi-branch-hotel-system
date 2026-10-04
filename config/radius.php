<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cloud FreeRADIUS (reached via per-branch WireGuard tunnel)
    |--------------------------------------------------------------------------
    |
    | Laravel is the source of truth for tiers/vouchers; jobs sync users
    | into radcheck/radreply. The NAS (MikroTik) talks to RADIUS over the
    | tunnel so no public 1812/1813 is required.
    |
    */
    'host' => env('RADIUS_HOST', '100.64.0.1'),
    'auth_port' => (int) env('RADIUS_AUTH_PORT', 1812),
    'acct_port' => (int) env('RADIUS_ACCT_PORT', 1813),
    'coa_port' => (int) env('RADIUS_COA_PORT', 3799),

    'timeout_secs' => (int) env('RADIUS_TIMEOUT', 3),
    'retries' => (int) env('RADIUS_RETRIES', 2),

    // Second DB connection name holding radcheck/radreply/radacct.
    'connection' => env('RADIUS_DB_CONNECTION', 'radius'),

    'fake' => (bool) env('RADIUS_FAKE', true),
];
