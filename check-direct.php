<?php

$raw = file_get_contents(__DIR__.'/phpstan-direct.json');
echo 'Length: '.strlen($raw)."\n";
echo 'Last 200: '.substr($raw, -200)."\n";
echo "Ends with '}' : ".(substr($raw, -1) === '}' ? 'yes' : 'no')."\n";

// Try to find the structure
echo "\nDecode attempt:\n";
$data = json_decode($raw, true);
echo 'truncated: '.var_export($data['truncated'] ?? 'N/A', true)."\n";
echo 'error_details keys: '.implode(', ', array_keys($data['error_details']))."\n";

// Count messages per file
foreach ($data['error_details'] as $file => $msgs) {
    $short = str_replace('F:\\Projects\\Herd\\multi-branch-hotel-system\\', '', $file);
    echo "  $short: ".count($msgs)." messages\n";
}
