<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enforce entitlements for tenants without a subscription
    |--------------------------------------------------------------------------
    |
    | While tenants are being onboarded onto plans, a tenant with no
    | subscription row keeps unrestricted access. Turn this on once every
    | tenant has a subscription, so a missing row denies every entitlement.
    |
    */

    'enforce_without_subscription' => (bool) env('ENTITLEMENTS_ENFORCE_WITHOUT_SUBSCRIPTION', false),

    /*
    |--------------------------------------------------------------------------
    | Entitlements kept while a subscription is restricted or cancelled
    |--------------------------------------------------------------------------
    |
    | A restricted subscription blocks new reservations and premium modules,
    | but guests already in house must still be able to check out, see their
    | folio, and keep their keys and Wi-Fi.
    |
    */

    'restricted_keeps' => [
        'finance.folios',
        'pms.front_desk',
        'locks.issue',
        'wifi.vouchers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache lifetime (seconds) for resolved entitlements
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => 300,

];
