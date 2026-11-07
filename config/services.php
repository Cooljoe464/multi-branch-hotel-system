<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    ],

    'firs' => [
        'endpoint' => env('FIRS_ENDPOINT'),
        'api_key' => env('FIRS_API_KEY'),
        'status_endpoint' => env('FIRS_STATUS_ENDPOINT'),
        'credit_note_endpoint' => env('FIRS_CREDIT_NOTE_ENDPOINT'),
        'reconcile_endpoint' => env('FIRS_RECONCILE_ENDPOINT'),
    ],

    // Identity OCR vendor (NIN/passport scans). Empty endpoint disables
    // vendor OCR and captures stay manual with ocr_pending status.
    // Overridable per platform through the SecretManager key ocr.api_key.
    'ocr' => [
        'endpoint' => env('OCR_ENDPOINT'),
        'key' => env('OCR_API_KEY'),
    ],

    // Accounting ledger OAuth apps. Per-branch client credentials live in
    // branch_secrets (xero.client_id, xero.client_secret, …) and win over
    // these environment-wide defaults.
    'xero' => [
        'client_id' => env('XERO_CLIENT_ID'),
        'client_secret' => env('XERO_CLIENT_SECRET'),
    ],

    'quickbooks' => [
        'client_id' => env('QUICKBOOKS_CLIENT_ID'),
        'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    ],

    'sage' => [
        'client_id' => env('SAGE_CLIENT_ID'),
        'client_secret' => env('SAGE_CLIENT_SECRET'),
    ],

    // PBX/telephony vendor adapter. 'null' is the vendor-less placeholder:
    // CDRs only arrive through the signed webhook until a real driver
    // (3CX, Asterisk, …) is registered in PbxDriverManager.
    'pbx' => [
        'driver' => env('PBX_DRIVER', 'null'),
    ],

    'whatsapp' => [
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v20.0'),
    ],

];
