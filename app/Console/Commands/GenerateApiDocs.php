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
            'api.v1.reports.concierge' => [
                'summary' => 'Concierge ROI report: deflection rate, revenue impact and sentiment trends.',
                'abilities' => ['analytics.view'],
                'parameters' => [$branchHeader],
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
            'api.v1.forecasts' => [
                'summary' => 'Read-only demand forecast feed for a date range.',
                'abilities' => ['analytics.view'],
                'parameters' => [
                    $branchHeader,
                    ['name' => 'from', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'to', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                ],
            ],
            'api.v1.me.entitlements' => [
                'summary' => 'Resolved entitlements for the token property tenant; metered is false until it has a subscription.',
                'abilities' => ['saas.view'],
                'parameters' => [$branchHeader],
            ],
            'api.v1.saas.subscription' => [
                'summary' => 'Current subscription state and plan for the token property tenant.',
                'abilities' => ['saas.view'],
                'parameters' => [$branchHeader],
            ],
            'api.v1.saas.invoices' => [
                'summary' => 'The latest platform invoices for the token property tenant.',
                'abilities' => ['saas.view'],
                'parameters' => [$branchHeader],
            ],
            'public.v1.holds.store' => [
                'summary' => 'Place a short-lived inventory hold from an unexpired, unused quote. Browser preflight identifies the key with the publishable_key query parameter.',
                'security' => [['publicApiKey' => []]],
                'abilities' => ['holds.create'],
                'parameters' => [
                    ['name' => 'X-API-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'publishable_key', 'in' => 'query', 'required' => false, 'description' => 'Use only on browser OPTIONS preflight; actual requests send X-API-Key.', 'schema' => ['type' => 'string']],
                    $idempotencyHeader,
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['quote_token', 'session_id'],
                                'properties' => [
                                    'quote_token' => ['type' => 'string'],
                                    'session_id' => ['type' => 'string', 'maxLength' => 128],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'public.v1.properties.show' => [
                'summary' => 'Return public property details for the property bound to this API key.',
                'security' => [['publicApiKey' => []]],
                'parameters' => [['name' => 'propertyId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
            ],
            'public.v1.room-types.index' => [
                'summary' => 'List active room types and public descriptive details for this property.',
                'security' => [['publicApiKey' => []]],
                'parameters' => [],
            ],
            'public.v1.availability' => [
                'summary' => 'Check room-type availability for a future stay at the property bound to this key.',
                'security' => [['publicApiKey' => []]],
                'parameters' => [
                    ['name' => 'room_type_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'check_in', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'check_out', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                ],
            ],
            'public.v1.quotes.store' => [
                'summary' => 'Create an expiring rate and tax quote for a future stay.',
                'security' => [['publicApiKey' => []]],
                'parameters' => [$idempotencyHeader],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['room_type_id', 'check_in', 'check_out'],
                                'properties' => [
                                    'room_type_id' => ['type' => 'integer'],
                                    'rate_plan_id' => ['type' => 'integer'],
                                    'check_in' => ['type' => 'string', 'format' => 'date'],
                                    'check_out' => ['type' => 'string', 'format' => 'date'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'public.v1.holds.destroy' => [
                'summary' => 'Release an inventory hold owned by the publishable API key. Browser preflight identifies the key with the publishable_key query parameter.',
                'security' => [['publicApiKey' => []]],
                'abilities' => ['holds.release'],
                'parameters' => [
                    ['name' => 'X-API-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'publishable_key', 'in' => 'query', 'required' => false, 'description' => 'Use only on browser OPTIONS preflight; actual requests send X-API-Key.', 'schema' => ['type' => 'string']],
                    $idempotencyHeader,
                ],
            ],
            'public.v1.payments.intents.store' => [
                'summary' => 'Initialize hosted checkout for an active inventory hold using the property payment connection.',
                'security' => [['publicApiKey' => []]],
                'parameters' => [$idempotencyHeader],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['hold_id', 'guest_name', 'guest_email', 'adults'],
                                'properties' => [
                                    'hold_id' => ['type' => 'integer'],
                                    'guest_name' => ['type' => 'string', 'maxLength' => 128],
                                    'guest_email' => ['type' => 'string', 'format' => 'email'],
                                    'guest_phone' => ['type' => 'string', 'maxLength' => 32],
                                    'adults' => ['type' => 'integer', 'minimum' => 1],
                                    'children' => ['type' => 'integer', 'minimum' => 0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'public.v1.reservations.store' => [
                'summary' => 'Confirm a verified payment intent into a confirmed public booking.',
                'security' => [['publicApiKey' => []]],
                'abilities' => ['reservations.create'],
                'parameters' => [$idempotencyHeader],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['payment_intent_id'],
                                'properties' => [
                                    'payment_intent_id' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'public.v1.reservations.show' => [
                'summary' => 'Read a confirmed public booking using its one-time-issued booking access token.',
                'security' => [['publicApiKey' => []]],
                'parameters' => [
                    ['name' => 'confirmationNumber', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'X-Booking-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ],
            ],
            'public.v1.reservations.cancel' => [
                'summary' => 'Cancel a public booking under the property cancellation policy and return any cancellation fee.',
                'security' => [['publicApiKey' => []]],
                'parameters' => [
                    ['name' => 'confirmationNumber', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'X-Booking-Token', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    $idempotencyHeader,
                ],
            ],
            'api.v1.conflicts.index' => [
                'summary' => 'List offline room conflicts awaiting supervisor resolution at the branch.',
                'abilities' => ['offline.review'],
                'parameters' => [$branchHeader],
            ],
            'api.v1.conflicts.resolve' => [
                'summary' => 'Resolve an offline room conflict by reassigning the guest to another clean room or discarding the assignment.',
                'abilities' => ['offline.review'],
                'parameters' => [$branchHeader, $idempotencyHeader],
            ],
            'api.v1.conflicts.discard' => [
                'summary' => 'Discard an offline room conflict assignment without creating a provisional stay.',
                'abilities' => ['offline.review'],
                'parameters' => [$branchHeader, $idempotencyHeader],
            ],
            'api.v1.sync.push' => [
                'summary' => 'Push offline command envelopes and receive applied or conflict statuses.',
                'abilities' => ['offline.sync'],
                'parameters' => [$branchHeader, $idempotencyHeader],
            ],
            'api.v1.sync.pull' => [
                'summary' => 'Pull command and provisional-stay status changes for an offline device.',
                'abilities' => ['offline.sync'],
                'parameters' => [$branchHeader],
            ],
            'api.v1.sync.override-token' => [
                'summary' => 'Issue a single-use, expiring manager override token for a conflict.',
                'abilities' => ['reservations.checkin'],
                'parameters' => [$branchHeader, $idempotencyHeader],
            ],
            'api.v1.edge.heartbeat' => [
                'summary' => 'Record an edge-agent heartbeat after protocol compatibility validation.',
                'abilities' => ['edge.heartbeat'],
                'parameters' => [
                    ['name' => 'Authorization', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'X-Edge-Protocol', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ],
            ],
            'api.v1.edge.policy' => [
                'summary' => 'Return the current and accepted previous edge protocol versions.',
                'abilities' => ['edge.policy'],
                'parameters' => [
                    ['name' => 'Authorization', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'X-Edge-Protocol', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ],
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
            if (is_string($name) && (str_starts_with($name, 'api.v1.') || str_starts_with($name, 'public.v1.'))) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The assembled OpenAPI 3.1 document served to the developer docs
     * portal and exported as the machine-readable snapshot.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $paths = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || (! str_starts_with($name, 'api.v1.') && ! str_starts_with($name, 'public.v1.'))) {
                continue;
            }

            $spec = self::specs()[$name] ?? null;

            if ($spec === null) {
                continue;
            }

            $uri = '/'.ltrim($route->uri(), '/');

            foreach ($route->methods() as $method) {
                $verb = is_string($method) ? strtolower($method) : '';

                if ($verb === '' || $verb === 'head') {
                    continue;
                }

                $responses = [
                    '200' => ['description' => 'OK'],
                    '201' => ['description' => 'Created'],
                    '401' => ['description' => 'Missing, invalid, or expired token.'],
                    '403' => ['description' => 'Scope or branch outside the token.'],
                    '404' => ['description' => 'Unknown id at this property.'],
                    '422' => ['description' => 'Validation failed or idempotency key missing.'],
                ];

                if (str_starts_with($name, 'api.v1.edge.')) {
                    $responses['426'] = ['description' => 'Unsupported protocol version; response includes current and supported versions.'];
                }

                $paths[$uri][$verb] = [
                    'operationId' => $name,
                    'summary' => $spec['summary'],
                    'security' => $spec['security'] ?? [['bearerAuth' => $spec['abilities']]],
                    'parameters' => $spec['parameters'],
                    'responses' => $responses,
                ];

                if (isset($spec['requestBody'])) {
                    $paths[$uri][$verb]['requestBody'] = $spec['requestBody'];
                }

                if (str_starts_with($name, 'public.v1.')) {
                    $paths[$uri][$verb]['servers'] = [['url' => '/']];
                }
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Hotel PMS and Public API', 'version' => '1'],
            'servers' => [['url' => '/api']],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum consumer token.'],
                    'publicApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key', 'description' => 'Branch-scoped public API key.'],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /**
     * The generated TypeScript client source for the `@hospitalityos/client`
     * package, derived from the assembled OpenAPI document.
     */
    public static function sdkSource(): string
    {
        $document = self::document();
        $paths = $document['paths'];
        ksort($paths);

        $blocks = [];

        foreach ($paths as $uri => $methods) {
            foreach ($methods as $verb => $operation) {
                $blocks[] = self::sdkOperation((string) $uri, (string) $verb, $operation);
            }
        }

        return self::sdkHeader()."\n\n".implode("\n\n", $blocks)."\n";
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private static function sdkOperation(string $uri, string $verb, array $operation): string
    {
        $operationId = (string) $operation['operationId'];
        $segments = collect(explode('.', $operationId))
            ->reject(fn (string $segment): bool => $segment === 'v1')
            ->values()
            ->toArray();
        $camelize = fn (string $segment): string => lcfirst(implode('', array_map(
            fn (string $word): string => ucfirst($word),
            explode(' ', ucwords(str_replace(['-', '_'], ' ', $segment))),
        )));
        $typeName = implode('', array_map(
            fn (string $segment): string => ucfirst($camelize($segment)),
            $segments,
        ));
        $functionName = lcfirst($typeName);
        $paramsType = $typeName.'Params';
        $responseType = $typeName.'Response';
        $methodType = strtoupper($verb);
        $hasBody = ! in_array($methodType, ['GET', 'DELETE'], true);
        $hasRequiredParams = false;

        preg_match_all('/\{([\w-]+)\}/', $uri, $matches);
        $pathKeys = array_map(
            fn (string $key): string => self::sdkCamelCase($key),
            $matches[1] ?? [],
        );

        $paramFields = [];
        $branchScoped = false;
        foreach ($operation['parameters'] ?? [] as $parameter) {
            $location = (string) ($parameter['in'] ?? '');
            $name = (string) ($parameter['name'] ?? '');

            if ($location === 'query' || $location === 'path') {
                $paramFields[] = [
                    'name' => self::sdkCamelCase($name),
                    'required' => (bool) ($parameter['required'] ?? false),
                    'type' => self::sdkType((string) ($parameter['schema']['type'] ?? 'string')),
                ];
            }

            if ($name === 'X-Branch-Id') {
                $branchScoped = true;
            }
        }

        if ($branchScoped) {
            $paramFields[] = ['name' => 'branchId', 'required' => false, 'type' => 'number'];
        }

        foreach ($paramFields as $field) {
            if ($field['required']) {
                $hasRequiredParams = true;
            }
        }

        $excluded = '['.implode(', ', array_map(fn (string $key): string => '"'.$key.'"', $pathKeys)).']';

        $block = [
            'interface '.$paramsType.' {',
        ];
        foreach ($paramFields as $field) {
            $block[] = '    '.$field['name'].($field['required'] ? '' : '?').': '.$field['type'].';';
        }
        $block[] = '}';
        $block[] = '';
        $block[] = 'type '.$responseType.' = Record<string, unknown>;';
        $block[] = '';
        $block[] = 'export const '.$functionName.' = async (';
        $default = $hasRequiredParams ? '' : ' = {},';
        $block[] = '    params: '.$paramsType.$default.',';
        $block[] = '    options: ClientOptions = {},';
        $block[] = '): Promise<'.$responseType.'> => {';
        $block[] = '    const response = await fetch(';
        $block[] = '        `${options.baseUrl ?? "http://localhost"}${buildPath("'.$uri.'", params)}${buildQueryString(params, '.$excluded.')}`,';
        $block[] = '        {';
        $block[] = '            method: "'.$methodType.'",';
        $block[] = '            headers: {';
        $block[] = '                Accept: "application/json",';
        $block[] = '                ...(options.bearerToken ? { Authorization: `Bearer ${options.bearerToken}` } : {}),';
        $block[] = '                ...(options.apiKey ? { "X-API-Key": options.apiKey } : {}),';
        $block[] = '                ...(options.idempotencyKey ? { "Idempotency-Key": options.idempotencyKey } : {}),';
        if ($branchScoped) {
            $block[] = '                ...(params.branchId ? { "X-Branch-Id": String(params.branchId) } : {}),';
        }
        $block[] = '            },';
        if ($hasBody) {
            $block[] = '            body: JSON.stringify(params),';
        }
        $block[] = '        },';
        $block[] = '    );';
        $block[] = '';
        $block[] = '    if (!response.ok) {';
        $block[] = '        throw new Error(`Request failed with status ${response.status}`);';
        $block[] = '    }';
        $block[] = '';
        $block[] = '    return (await response.json()) as '.$responseType.';';
        $block[] = '};';

        return implode(PHP_EOL, $block);
    }

    private static function sdkHeader(): string
    {
        return implode("\n", [
            '/**',
            ' * Generated by `php artisan openapi:sdk`. Do not edit by hand.',
            ' * Source of truth: the OpenAPI 3.1 document served at /developer/docs.',
            ' */',
            '',
            'export interface ClientOptions {',
            '    baseUrl?: string;',
            '    bearerToken?: string;',
            '    apiKey?: string;',
            '    idempotencyKey?: string;',
            '}',
            '',
            'const buildQueryString = (params: Record<string, unknown>, excluded: string[] = []): string => {',
            '    const search = new URLSearchParams();',
            '',
            '    for (const [key, value] of Object.entries(params)) {',
            '        if (excluded.includes(key)) {',
            '            continue;',
            '        }',
            '',
            '        if (value === undefined || value === null || value === "") {',
            '            continue;',
            '        }',
            '',
            '        search.append(key, String(value));',
            '    }',
            '',
            '    const queryString = search.toString();',
            '',
            '    return queryString === "" ? "" : `?${queryString}`;',
            '};',
            '',
            'const buildPath = (path: string, params: Record<string, unknown>): string =>',
            '    path.replace(/\{([\w-]+)\}/g, (match, key: string) => encodeURIComponent(String(params[key] ?? "")));',
        ]);
    }

    private static function sdkType(string $type): string
    {
        return match ($type) {
            'integer', 'int32', 'int64' => 'number',
            'boolean', 'bool' => 'boolean',
            default => 'string',
        };
    }

    private static function sdkCamelCase(string $value): string
    {
        $words = explode(' ', ucwords(str_replace(['-', '_'], ' ', $value)));

        return lcfirst(implode('', array_map(fn (string $word): string => ucfirst($word), $words)));
    }

    public function handle(): int
    {
        foreach (self::documentedRoutes() as $route) {
            if (! array_key_exists($route, self::specs())) {
                $this->error("Undocumented API route: {$route}");

                return 1;
            }
        }

        $document = self::document();
        $paths = $document['paths'];
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
