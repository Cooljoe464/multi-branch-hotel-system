<?php

// Run PHPStan on specific paths and collect all relevant errors
$paths = [
    'app/Console',
    'app/Actions',
    'app/Concerns',
    'app/Contracts',
    'app/Events',
    'app/Exports',
    'app/Http',
    'app/Imports',
    'app/Jobs',
    'app/Models',
    'app/Notifications',
    'app/Policies',
    'app/Providers',
    'app/Services',
    'config',
    'database',
    'routes',
    'bootstrap/app.php',
];

$targetIds = ['missingType.parameter', 'argument.type', 'return.type', 'property.notFound'];
$allResults = [];

foreach ($paths as $path) {
    if (! file_exists($path)) {
        continue;
    }

    $cmd = 'php vendor/bin/phpstan analyse --level=7 --no-progress --error-format=json '.escapeshellarg($path).' 2>&1';
    $output = shell_exec($cmd);

    if (! $output) {
        continue;
    }

    $data = json_decode($output, true);
    if (! $data || ! isset($data['error_details'])) {
        continue;
    }

    foreach ($data['error_details'] as $file => $messages) {
        $short = str_replace('F:\\Projects\\Herd\\multi-branch-hotel-system\\', '', $file);
        foreach ($messages as $msg) {
            if (in_array($msg['identifier'], $targetIds, true)) {
                $allResults[] = $short.'|'.$msg['line'].'|'.$msg['identifier'].'|'.$msg['message'];
            }
        }
    }
}

file_put_contents(__DIR__.'/all-relevant-errors.txt', implode("\n", $allResults)."\n");
echo 'Total relevant errors: '.count($allResults)."\n";
foreach ($allResults as $r) {
    echo $r."\n";
}
