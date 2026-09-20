<?php

$stdout = file_get_contents(__DIR__.'/phpstan-stdout.txt');
echo 'First 500: '.substr($stdout, 0, 500)."\n";
echo "---\n";
$data = json_decode($stdout, true);
if ($data) {
    echo 'Keys: '.implode(', ', array_keys($data))."\n";
    if (isset($data['totals'])) {
        echo 'Totals: '.json_encode($data['totals'])."\n";
    }
} else {
    echo "Decode failed\n";
}
