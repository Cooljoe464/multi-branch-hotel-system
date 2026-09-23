<?php

use App\Console\Commands\GenerateApiDocs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('documents every versioned route and keeps the snapshot fresh', function () {
    $routes = GenerateApiDocs::documentedRoutes();
    $specs = array_keys(GenerateApiDocs::specs());

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        expect($specs)->toContain($route);
    }

    $exit = Artisan::call('api:docs', ['--check' => true]);

    expect($exit)->toBe(0);
});
