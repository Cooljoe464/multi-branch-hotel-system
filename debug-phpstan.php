<?php

$c = file_get_contents(__DIR__.'/phpstan-raw.json');
echo 'First bytes hex: ';
for ($i = 0; $i < 10; $i++) {
    echo bin2hex($c[$i]).' ';
}
echo "\n";
echo 'Total length: '.strlen($c)."\n";

// Try to find if it's wrapped
$trimmed = trim($c);
echo 'First char: '.$trimmed[0]."\n";
echo 'Last char: '.$trimmed[strlen($trimmed) - 1]."\n";

// Check for BOM
if (substr($c, 0, 3) === "\xEF\xBB\xBF") {
    echo "Has UTF-8 BOM\n";
}

// Try json_decode
$data = json_decode($c, true);
if ($data) {
    echo "JSON decoded successfully\n";
    echo 'Top-level keys: '.implode(', ', array_keys($data))."\n";
} else {
    echo 'JSON decode failed. Error: '.json_last_error_msg()."\n";

    // Maybe the output starts with something before the JSON
    // Find first { or [
    $pos = strpos($c, '{');
    if ($pos !== false && $pos > 0) {
        echo "Found { at position $pos, content before: ".substr($c, 0, $pos)."\n";
    }
}
