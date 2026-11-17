<?php

return [
    // Public API key middleware applies the per-key allowlist dynamically.
    'paths' => ['api/*'],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
