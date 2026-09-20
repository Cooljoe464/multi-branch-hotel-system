<?php

// The raw output is UTF-16LE, convert it
$raw = file_get_contents(__DIR__.'/phpstan-raw-out.txt');
// Check for BOM
if (substr($raw, 0, 2) === "\xFF\xFE") {
    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
} else {
    $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
}
file_put_contents(__DIR__.'/phpstan-raw-readable.txt', $raw);
echo 'Converted. Length: '.strlen($raw)."\n";

$lines = explode("\n", $raw);
echo 'Lines: '.count($lines)."\n";

// Count relevant errors
$targetIds = ['missingType.parameter', 'argument.type', 'return.type', 'property.notFound'];
$count = 0;
foreach ($lines as $line) {
    foreach ($targetIds as $id) {
        if (strpos($line, '"identifier":"'.$id.'"') !== false) {
            $count++;
            break;
        }
    }
}
echo "Matching errors: $count\n";
