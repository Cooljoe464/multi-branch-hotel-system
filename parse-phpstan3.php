<?php

$c = file_get_contents(__DIR__.'/phpstan-raw.json');

// Try different encodings
$encodings = ['UTF-16LE', 'UTF-16BE', 'UTF-16'];
foreach ($encodings as $enc) {
    $converted = mb_convert_encoding($c, 'UTF-8', $enc);
    $data = json_decode($converted, true);
    if ($data) {
        echo "Decoded with $enc\n";
        break;
    }
}

if (! $data) {
    // Try stripping BOM manually
    $trimmed = $c;
    // Remove BOM
    if (substr($trimmed, 0, 2) === "\xFF\xFE") {
        $trimmed = substr($trimmed, 2);
    }

    // Try decoding as raw UTF-16LE
    $converted = @mb_convert_encoding($trimmed, 'UTF-8', 'UTF-16LE');
    $data = json_decode($converted, true);

    if (! $data) {
        // Try converting byte-by-byte for simple ASCII JSON
        $converted = '';
        for ($i = 0; $i < strlen($trimmed); $i += 2) {
            $byte = $trimmed[$i];
            if ($byte !== "\x00") {
                $converted .= $byte;
            }
        }

        echo 'Manual conversion length: '.strlen($converted)."\n";
        echo 'First 200 chars: '.substr($converted, 0, 200)."\n";

        $data = json_decode($converted, true);
        if ($data) {
            echo "Decoded with manual byte extraction\n";
        } else {
            echo 'Still failed: '.json_last_error_msg()."\n";
            echo 'First error context: '.substr($converted, max(0, json_last_error() - 50), 100)."\n";
        }
    }
}

if ($data) {
    echo 'Total errors: '.($data['totals']['errors'] ?? count($data['files'] ?? []))."\n\n";

    $targetIds = ['missingType.parameter', 'argument.type', 'return.type', 'property.notFound'];
    $results = [];

    foreach ($data['files'] as $file => $messages) {
        foreach ($messages as $msg) {
            if (in_array($msg['identifier'], $targetIds, true)) {
                $short = str_replace('F:\\Projects\\Herd\\multi-branch-hotel-system\\', '', $file);
                $results[] = $short.':'.$msg['line'].' ['.$msg['identifier'].'] '.$msg['message'];
            }
        }
    }

    file_put_contents(__DIR__.'/phpstan-filtered.txt', implode("\n", $results)."\n\nTotal: ".count($results)."\n");
    echo 'Filtered errors written: '.count($results)."\n";
}
