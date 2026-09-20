<?php

$content = file_get_contents(__DIR__.'/phpstan-raw.json');
file_put_contents(__DIR__.'/phpstan-raw-readable.txt', $content);
echo 'Done: '.strlen($content)." bytes\n";

$data = json_decode($content, true);
if (! $data) {
    echo "JSON decode failed\n";
    exit(1);
}

$targetIds = ['missingType.parameter', 'argument.type', 'return.type', 'property.notFound'];
$results = [];

if (isset($data['files'])) {
    foreach ($data['files'] as $file => $messages) {
        foreach ($messages as $msg) {
            if (in_array($msg['identifier'], $targetIds, true)) {
                $short = str_replace('F:\\Projects\\Herd\\multi-branch-hotel-system\\', '', $file);
                $results[] = $short.':'.$msg['line'].' ['.$msg['identifier'].'] '.$msg['message'];
            }
        }
    }
} elseif (isset($data['error_details'])) {
    foreach ($data['error_details'] as $file => $messages) {
        foreach ($messages as $msg) {
            if (in_array($msg['identifier'], $targetIds, true)) {
                $short = str_replace('F:\\Projects\\Herd\\multi-branch-hotel-system\\', '', $file);
                $results[] = $short.':'.$msg['line'].' ['.$msg['identifier'].'] '.$msg['message'];
            }
        }
    }
}

file_put_contents(__DIR__.'/phpstan-filtered.txt', implode("\n", $results)."\nTotal: ".count($results)."\n");
echo 'Found '.count($results)." matching errors\n";
