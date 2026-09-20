<?php

// Run phpstan directly, writing output to a file
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', __DIR__.'/phpstan-direct.json', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open(
    'php vendor/bin/phpstan analyse --level=7 --no-progress --error-format=json',
    $descriptors,
    $pipes
);

// Wait for completion
fclose($pipes[0]);
fclose($pipes[2]);
$returnCode = proc_close($process);

echo "Return code: $returnCode\n";

$raw = file_get_contents(__DIR__.'/phpstan-direct.json');
echo 'Raw length: '.strlen($raw)."\n";
echo 'First 100 hex: '.substr(bin2hex($raw), 0, 20)."\n";

// Check encoding
if (ord($raw[0]) == 0xFF && ord($raw[1]) == 0xFE) {
    echo "UTF-16LE detected\n";
    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    file_put_contents(__DIR__.'/phpstan-direct.json', $raw);
    echo 'Converted to UTF-8, new length: '.strlen($raw)."\n";
}

$data = json_decode($raw, true);
if (! $data) {
    echo 'JSON error: '.json_last_error_msg()."\n";
    exit(1);
}

echo 'Total errors: '.$data['errors']."\n";
echo 'Files: '.count($data['error_details'])."\n";
