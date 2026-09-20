<?php

$c = file_get_contents(__DIR__.'/phpstan-raw.json');

// Convert UTF-16 to UTF-8
$converted = mb_convert_encoding($c, 'UTF-8', 'UTF-16');

// Remove BOM if present
if (substr($converted, 0, 3) === "\xEF\xBB\xBF") {
    $converted = substr($converted, 3);
}

$converted = trim($converted);

// Check if wrapped in tool output
echo 'First 200: '.substr($converted, 0, 200)."\n\n";
echo 'Last 200: '.substr($converted, -200)."\n\n";

// Try to decode
$data = json_decode($converted, true);
if ($data) {
    echo 'Keys: '.implode(', ', array_keys($data))."\n";
    if (isset($data['files'])) {
        echo 'Files count: '.count($data['files'])."\n";
    }
    if (isset($data['error_details'])) {
        echo 'error_details count: '.count($data['error_details'])."\n";
    }
} else {
    echo 'Decode failed: '.json_last_error_msg()."\n";
    echo 'Error at position: '.json_last_error()."\n";
    $pos = json_last_error();
    if ($pos > 0) {
        echo 'Context: '.substr($converted, max(0, $pos - 100), 200)."\n";
    }
}
