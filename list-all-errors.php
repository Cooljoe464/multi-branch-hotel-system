<?php

$raw = file_get_contents(__DIR__.'/phpstan-raw-output.json');
$data = json_decode($raw, true);

// Pretty print the error_details to separate lines
$line = 1;
$parts = [];
foreach ($data['error_details'] as $file => $messages) {
    $short = str_replace('F:\\Projects\\Herd\\multi-branch-hotel-system\\', '', $file);
    foreach ($messages as $msg) {
        $parts[] = $short.'|'.$msg['line'].'|'.$msg['identifier'].'|'.$msg['message'];
    }
}

file_put_contents(__DIR__.'/phpstan-all-errors.txt', implode("\n", $parts)."\n");
echo 'Total error entries: '.count($parts)."\n";
echo 'Total files in error_details: '.count($data['error_details'])."\n";
