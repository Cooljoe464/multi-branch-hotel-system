<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enterprise SSO (OIDC)
    |--------------------------------------------------------------------------
    |
    | Generic OpenID Connect provider wiring for Google Workspace, Microsoft
    | Entra ID and Okta. Each provider points at an issuer that publishes an
    | OpenID Connect discovery document; the client credentials belong to the
    | corporate tenant that owns the property group.
    |
    */

    'providers' => [
        'google' => [
            'issuer' => env('SSO_GOOGLE_ISSUER', 'https://accounts.google.com'),
            'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
            'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
            'tenant' => env('SSO_GOOGLE_TENANT'),
        ],
        'microsoft' => [
            'issuer' => env('SSO_MICROSOFT_ISSUER', 'https://login.microsoftonline.com/common/v2.0'),
            'client_id' => env('SSO_MICROSOFT_CLIENT_ID'),
            'client_secret' => env('SSO_MICROSOFT_CLIENT_SECRET'),
            'tenant' => env('SSO_MICROSOFT_TENANT'),
        ],
        'okta' => [
            'issuer' => env('SSO_OKTA_ISSUER'),
            'client_id' => env('SSO_OKTA_CLIENT_ID'),
            'client_secret' => env('SSO_OKTA_CLIENT_SECRET'),
            'tenant' => env('SSO_OKTA_TENANT'),
        ],
    ],

];
