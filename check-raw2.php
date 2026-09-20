<?php

$raw = file_get_contents(__DIR__.'/phpstan-raw-out2.txt');
if (substr($raw, 0, 2) === "\xFF\xFE") {
    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
} else {
    $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
}
file_put_contents(__DIR__.'/phpstan-readable2.txt', $raw);
echo 'Length: '.strlen($raw)."\n";
$lines = explode("\n", $raw);
echo 'Lines: '.count($lines)."\n";
echo 'First line (first 200): '.substr($lines[0], 0, 200)."\n";
echo 'Last line (first 200): '.substr($lines[count($lines) - 1], 0, 200)."\n";
