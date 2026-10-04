<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hotspot network layout (single RouterOS per branch)
    |--------------------------------------------------------------------------
    |
    | Staff stays on a PSK SSID/VLAN for V1 (no EAP). Guests live on an
    | isolated VLAN bound to Hotspot + cloud RADIUS over WireGuard.
    |
    */
    'staff_vlan_id' => (int) env('HOTSPOT_STAFF_VLAN', 10),
    'guest_vlan_id' => (int) env('HOTSPOT_GUEST_VLAN', 30),

    'staff_subnet' => env('HOTSPOT_STAFF_SUBNET', '10.10.0.0/24'),
    'guest_subnet' => env('HOTSPOT_GUEST_SUBNET', '10.30.0.0/21'),

    'staff_ssid' => env('HOTSPOT_STAFF_SSID', 'STAFF'),
    'guest_ssid' => env('HOTSPOT_GUEST_SSID', 'GUEST-WIFI'),

    'portal_domain' => env('HOTSPOT_PORTAL_DOMAIN', 'guest.hotels.example'),

    'queue' => env('HOTSPOT_QUEUE', 'network'),

    'voucher_length' => (int) env('HOTSPOT_VOUCHER_LENGTH', 12),
];
