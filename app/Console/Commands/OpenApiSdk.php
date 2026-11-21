<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate the `@hospitalityos/client` TypeScript package from the
 * assembled OpenAPI document served at /developer/docs.
 */
class OpenApiSdk extends Command
{
    protected $signature = 'openapi:sdk {--dir : Directory to write the generated TypeScript client into}';

    protected $description = 'Generate the TypeScript API client from the OpenAPI snapshot.';

    public function handle(): int
    {
        $target = $this->option('dir') && is_dir((string) $this->option('dir'))
            ? (string) $this->option('dir')
            : base_path('sdk');

        if (! is_dir($target) && ! mkdir($target, 0755, true) && ! is_dir($target)) {
            $this->error('Unable to create the SDK directory at '.$target.'.');

            return 1;
        }

        file_put_contents($target.'/client.ts', GenerateApiDocs::sdkSource());

        file_put_contents($target.'/package.json', json_encode([
            'name' => '@hospitalityos/client',
            'version' => '1.0.0',
            'description' => 'TypeScript client generated from the HospitalityOS OpenAPI snapshot.',
            'type' => 'module',
            'main' => './client.ts',
            'types' => './client.ts',
            'scripts' => [
                'generate' => 'php artisan openapi:sdk',
            ],
            'license' => 'MIT',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('Wrote the TypeScript client to '.$target.'/client.ts.');

        return 0;
    }
}
