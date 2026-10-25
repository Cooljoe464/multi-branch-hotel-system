<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identity encryption
    |--------------------------------------------------------------------------
    |
    | ID numbers rest on Laravel's encrypted cast (APP_KEY envelope).
    | Per-branch key wrap: set a branch data-key in settings under
    | privacy.data_key to scope a future envelope rotation; rotation
    | re-saves through EncryptIdsJob semantics (decrypt-verify-write).
    |
    */
    'key_wrap' => [
        'driver' => env('GDPR_KEY_WRAP', 'app_key'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention defaults (days) by data class, overridable per policy.
    |--------------------------------------------------------------------------
    */
    'retention_defaults' => [
        'folio_lines' => 2555,
        'id_scans' => 365,
        'marketing' => 730,
    ],
];
