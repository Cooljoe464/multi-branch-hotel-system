<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Generate docs/api-v1.json from the named api.v1.* routes. Each
 * endpoint carries its spec inline below; the ApiDocsTest fails CI
 * when a route has no spec, so undocumented endpoints can't ship.
 */
class GenerateApiDocs extends Command
{
    protected $signature = 'api:docs {--check : Exit non-zero when the snapshot is stale}';

    protected $description = 'Generate the versioned API OpenAPI snapshot.';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function specs(): array
    {
        $branchHeader = [
            'name' => 'X-Branch-Id',
            'in' => 'header',
            'required' => false,
            'description' => 'Required for multi-property tokens; ignored for single-property tokens.',
            'schema' => ['type' => 'integer'],
        ];
        $idempotencyHeader = [
            'name' => 'X-Idempotency-Key',
            'in' => 'header',
            'required' => true,
            'description' => 'Write key. Replays return the stored response with Idempotent-Replayed.',
            'schema' => ['type' => 'string'],
        ];

        return [
            'api.v1.availability' => [
                'summary' => 'Quote availability for a room type and stay window.',
                'abilities' => ['availability.view'],
                'parameters' => [
                    $branchHeader,
                    ['name' => 'room_type_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'check_in', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'check_out', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                ],
            ],
            'api.v1.reservations.store' => [
                'summary' => 'Create a confirmed reservation at the token property.',
                'abilities' => ['reservations.create'],
                'parameters' => [$branchHeader, $idempotencyHeader],
            ],
            'api.v1.reservations.show' => [
                'summary' => 'Fetch one reservation at the token property.',
                'abilities' => ['reservations.view'],
                'parameters' => [$branchHeader],
            ],
            'api.v1.reservations.cancel' => [
                'summary' => 'Cancel a reservation, posting any penalty to the folio.',
                'abilities' => ['reservations.cancel'],
                'parameters' => [$branchHeader, $idempotencyHeader],
            ],
            'api.v1.folios.show' => [
                'summary' => 'Fetch a folio with live transaction lines.',
                'abilities' => ['folios.view'],
                'parameters' => [$branchHeader],
            ],
            'api.v1.rates' => [
                'summary' => 'List active rate plans, optionally quoted for a stay.',
                'abilities' => ['rate_plans.view'],
                'parameters' => [
                    $branchHeader,
                    ['name' => 'room_type_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                    ['name' => 'check_in', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'check_out', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                ],
            ],
            'api.v1.revenue' => [
                'summary' => 'Revenue KPIs (ADR/RevPAR/pace/budgets) for a date range.',
                'abilities' => ['analytics.view'],
                'parameters' => [
                    $branchHeader,
                    ['name' => 'from', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'to', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                ],
            ],
            'api.v1.webhooks.deliveries' => [
                'summary' => 'List recent webhook deliveries for this consumer.',
                'abilities' => ['webhooks.replay'],
                'parameters' => [$branchHeader],
            ],
            'api.v1.webhooks.replay' => [
                'summary' => 'Requeue a delivery; the frozen payload re-signs identically.',
                'abilities' => ['webhooks.replay'],
                'parameters' => [$branchHeader, $idempotencyHeader],
            ],
            'api.v1.warehouse.manifests' => [
                'summary' => 'List warehouse manifests with files, row counts and checksums.',
                'abilities' => ['analytics.export_warehouse'],
                'parameters' => [$branchHeader],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function documentedRoutes(): array
    {
        $names = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'api.v1.')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function handle(): int
    {
        $paths = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || ! str_starts_with($name, 'api.v1.')) {
                continue;
            }

            $spec = self::specs()[$name] ?? null;

            if ($spec === null) {
                $this->error("Undocumented API route: {$name}");

                return 1;
            }

            $uri = '/'.ltrim($route->uri(), '/');

            foreach ($route->methods() as $method) {
                $verb = is_string($method) ? strtolower($method) : '';

                if ($verb === '' || $verb === 'head') {
                    continue;
                }

                $paths[$uri][$verb] = [
                    'operationId' => $name,
                    'summary' => $spec['summary'],
                    'security' => [['bearerAuth' => $spec['abilities']]],
                    'parameters' => $spec['parameters'],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                        '201' => ['description' => 'Created'],
                        '401' => ['description' => 'Missing, invalid, or expired token.'],
                        '403' => ['description' => 'Scope or branch outside the token.'],
                        '404' => ['description' => 'Unknown id at this property.'],
                        '422' => ['description' => 'Validation failed or idempotency key missing.'],
                    ],
                ];
            }
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Hotel PMS Partner API', 'version' => '1'],
            'servers' => [['url' => '/api']],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum consumer token.'],
                ],
            ],
            'paths' => $paths,
        ];

        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            $this->error('OpenAPI encoding failed.');

            return 1;
        }

        $path = base_path('docs/api-v1.json');

        if ($this->option('check') && file_exists($path) && file_get_contents($path) !== $encoded."\n") {
            $this->error('docs/api-v1.json is stale. Run php artisan api:docs.');

            return 1;
        }

        file_put_contents($path, $encoded."\n");
        $this->info('Wrote '.count($paths).' paths to docs/api-v1.json.');

        return 0;
    }
}
