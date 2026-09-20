<?php

$raw = shell_exec('php vendor/bin/phpstan analyse --level=7 --no-progress --error-format=json 2>&1');
file_put_contents(__DIR__.'/phpstan-raw-output.json', $raw);
echo 'Raw length: '.strlen($raw)."\n";
echo 'First 200: '.substr($raw, 0, 200)."\n";
