<?php

$content = file_get_contents(__DIR__.'/phpstan-full.txt');
$data = json_decode($content, true);

$targetIds = ['missingType.parameter', 'argument.type', 'return.type', 'property.notFound'];
$results = [];

if (isset($data['error_details'])) {
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
