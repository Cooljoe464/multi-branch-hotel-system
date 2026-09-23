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
    | OCR vendor
    |--------------------------------------------------------------------------
    |
    | Empty endpoint disables vendor OCR: captures stay manual with
    | ocr_pending status. Configure per environment, never commit keys.
    |
    */
    'ocr' => [
        'endpoint' => env('OCR_ENDPOINT'),
        'key' => env('OCR_API_KEY'),
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
