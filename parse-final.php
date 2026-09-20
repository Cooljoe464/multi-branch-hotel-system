<?php

$raw = file_get_contents(__DIR__.'/phpstan-raw-output.json');
$data = json_decode($raw, true);

if (! $data || ! isset($data['error_details'])) {
    echo "ERROR: No error_details in output\n";
    echo 'Keys: '.implode(', ', array_keys($data ?? []))."\n";
    echo 'Raw starts with: '.substr($raw, 0, 200)."\n";
    exit(1);
}

$targetIds = ['missingType.parameter', 'argument.type', 'return.type', 'property.notFound'];
$excludeBranchController = true;
$results = [];

foreach ($data['error_details'] as $file => $messages) {
    $short = str_replace('F:\\Projects\\Herd\\multi-branch-hotel-system\\', '', $file);

    // Skip BranchController per user request
    if ($excludeBranchController && strpos($short, 'BranchController.php') !== false) {
        continue;
    }

    foreach ($messages as $msg) {
        if (! in_array($msg['identifier'], $targetIds, true)) {
            continue;
        }

        // Exclude union type issues from controllers (Collection|Model union types)
        // per user request: "excluding union type issues from controllers"
        if (strpos($short, 'Controllers/') !== false) {
            if (strpos($msg['message'], 'Collection<') !== false) {
                continue;
            }
        }

        $results[] = $short.':'.$msg['line'].' ['.$msg['identifier'].'] '.$msg['message'];
    }
}

file_put_contents(__DIR__.'/phpstan-filtered.txt', implode("\n", $results)."\n\nTotal: ".count($results)."\n");
echo 'Filtered: '.count($results)." errors\n";
