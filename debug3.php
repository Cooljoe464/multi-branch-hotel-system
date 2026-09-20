<?php

$stdout = file_get_contents(__DIR__.'/phpstan-stdout.txt');
$data = json_decode($stdout, true);
echo 'Truncated: '.($data['truncated'] ? 'true' : 'false')."\n";
echo 'Errors count in output: '.count($data['error_details'])." files\n";
echo 'Total errors reported: '.$data['errors']."\n";
