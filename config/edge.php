<?php

return [
    'current_version' => env('EDGE_PROTOCOL_VERSION', '1.4'),
    'previous_minor_versions' => (int) env('EDGE_PREVIOUS_MINOR_VERSIONS', 2),
];
