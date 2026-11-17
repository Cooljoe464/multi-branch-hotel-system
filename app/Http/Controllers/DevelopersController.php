<?php

namespace App\Http\Controllers;

use App\Exceptions\UnsafeWebhookUrl;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\ApiConsumer;
use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\WebhookDelivery;
use App\Services\PublicApiKeyService;
use App\Services\WebhookDispatcher;
use App\Services\WebhookUrlGuard;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\IpUtils;

class DevelopersController extends Controller
{
    use EnsuresBranchAccess;

    /**
     * @var list<string>
     */
    public const AVAILABLE_SCOPES = [
        'availability.view',
        'reservations.view',
        'reservations.create',
        'reservations.cancel',
        'folios.view',
        'rate_plans.view',
        'analytics.view',
        'analytics.export_warehouse',
        'webhooks.replay',
        'saas.view',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $branches = $user?->is_global_admin
            ? Branch::query()->where('is_active', true)->orderBy('id')->get(['id', 'name'])
            : ($user?->branches()->where('is_active', true)->orderBy('branches.id')->get(['branches.id', 'branches.name']) ?? collect());
        $branchIds = $branches->pluck('id')->map(fn (mixed $id): ?int => filter_var($id, FILTER_VALIDATE_INT) ?: null)->filter()->values()->all();

        $consumers = ApiConsumer::with('branch')->orderBy('id')->get()
            ->filter(fn (ApiConsumer $consumer): bool => $user?->is_global_admin === true
                || ($consumer->reachableBranchIds() !== [] && array_diff($consumer->reachableBranchIds(), $branchIds) === []))
            ->values();
        $consumerIds = $consumers->modelKeys();
        $consumers = $consumers->map(fn (ApiConsumer $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'branch_id' => $c->branch_id,
            'branch_name' => $c->branch?->name,
            'branch_ids' => $c->branch_ids ?? [],
            'scopes' => $c->scopes ?? [],
            'webhook_url' => $c->webhook_url,
            'has_webhook_secret' => $c->webhook_secret !== null,
            'grace_active' => $c->webhook_grace_until !== null && $c->webhook_grace_until->isFuture(),
            'is_active' => $c->is_active,
            'tokens_count' => $c->tokens()->count(),
        ])->all();

        $deliveries = WebhookDelivery::with('consumer')->whereIn('api_consumer_id', $consumerIds)->latest('id')->limit(50)->get()->map(fn (WebhookDelivery $d) => [
            'id' => $d->id,
            'consumer_name' => $d->consumer->name,
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'last_error' => $d->last_error,
            'created_at' => $d->created_at?->toIso8601String(),
        ])->all();

        $publicApiKeys = ApiKey::query()
            ->with('branch:id,name')
            ->whereIn('branch_id', $branchIds)
            ->orderBy('id')
            ->get()
            ->map(fn (ApiKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'branch_id' => $key->branch_id,
                'branch_name' => $key->branch?->name,
                'type' => $key->type,
                'environment' => $key->environment,
                'prefix' => $key->prefix,
                'allowed_origins' => $key->allowed_origins ?? [],
                'scopes' => $key->scopes,
                'revoked_at' => $key->revoked_at?->toIso8601String(),
                'previous_valid_until' => $key->previous_valid_until?->toIso8601String(),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
            ])->all();

        return Inertia::render('developers/Consumers', [
            'consumers' => $consumers,
            'deliveries' => $deliveries,
            'scopes' => self::AVAILABLE_SCOPES,
            'branches' => $branches,
            'publicApiKeys' => $publicApiKeys,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:128',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
            'scopes' => 'required|array|min:1',
            'scopes.*' => 'string|in:'.implode(',', self::AVAILABLE_SCOPES),
            'webhook_url' => ['nullable', 'url', 'max:256', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                try {
                    app(WebhookUrlGuard::class)->resolve($value);
                } catch (UnsafeWebhookUrl $exception) {
                    $fail($exception->getMessage());
                }
            }],
        ]);

        $branchId = $request->integer('branch_id');
        $branchIds = array_values(array_unique(array_filter(
            array_map(fn (mixed $branchId): int|false => filter_var($branchId, FILTER_VALIDATE_INT), $request->array('branch_ids')),
            fn (int|false $branchId): bool => is_int($branchId) && $branchId > 0,
        )));

        if ($branchId > 0 && $branchIds !== []) {
            return back()->withErrors(['branch_id' => 'Set a single property or a property list, not both.']);
        }

        $scopes = array_values(array_filter($request->array('scopes'), is_string(...)));
        $webhookUrl = $request->string('webhook_url')->value();
        $managedBranchIds = $branchId > 0 ? [$branchId] : $branchIds;

        foreach ($managedBranchIds as $managedBranchId) {
            $this->ensureBranchAccess(Branch::query()->findOrFail($managedBranchId));
        }

        $consumer = ApiConsumer::create([
            'name' => $request->string('name')->value(),
            'branch_id' => $branchId > 0 ? $branchId : null,
            'branch_ids' => $branchId > 0 ? null : $branchIds,
            'scopes' => $scopes,
            'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
        ]);

        $token = $consumer->createToken('default', $consumer->tokenAbilities(), now()->addYear())->plainTextToken;

        return redirect()->route('developers.index')
            ->with('token', $token)
            ->with('toast', ['type' => 'success', 'message' => "Consumer {$consumer->name} created. Copy the token now — it is shown once."]);
    }

    public function issueToken(Request $request, ApiConsumer $consumer): RedirectResponse
    {
        $this->ensureConsumerAccess($consumer);
        $token = $consumer->createToken('default', $consumer->tokenAbilities(), now()->addYear())->plainTextToken;

        return redirect()->route('developers.index')
            ->with('token', $token)
            ->with('toast', ['type' => 'success', 'message' => "New token issued for {$consumer->name}. Copy it now — it is shown once."]);
    }

    public function rotate(Request $request, ApiConsumer $consumer, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $this->ensureConsumerAccess($consumer);
        if ($consumer->webhook_secret === null && ! $request->filled('webhook_secret')) {
            return back()->withErrors(['webhook_secret' => 'Set an initial secret first.']);
        }

        if ($request->filled('webhook_secret')) {
            $request->validate(['webhook_secret' => 'string|min:32|max:128']);
            $consumer->update([
                'webhook_previous_secret' => $consumer->webhook_secret,
                'webhook_secret' => $request->string('webhook_secret')->value(),
                'webhook_grace_until' => now()->addHours(WebhookDispatcher::GRACE_HOURS),
            ]);
        } else {
            $dispatcher->rotateSecret($consumer);
        }

        return redirect()->route('developers.index')
            ->with('toast', ['type' => 'success', 'message' => "Webhook secret rotated for {$consumer->name}. The previous secret stays valid for 24h."]);
    }

    public function replay(Request $request, WebhookDelivery $delivery, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $this->ensureConsumerAccess($delivery->consumer);
        $dispatcher->replay($delivery);

        return redirect()->route('developers.index')
            ->with('toast', ['type' => 'success', 'message' => "Delivery #{$delivery->id} requeued."]);
    }

    public function toggle(ApiConsumer $consumer): RedirectResponse
    {
        $this->ensureConsumerAccess($consumer);
        $consumer->update(['is_active' => ! $consumer->is_active]);

        return redirect()->route('developers.index')
            ->with('toast', ['type' => 'success', 'message' => "Consumer {$consumer->name} ".($consumer->is_active ? 'activated' : 'deactivated').'.']);
    }

    public function issuePublicKey(Request $request, Branch $branch, PublicApiKeyService $keys): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', 'string', 'in:publishable,secret'],
            'environment' => ['required', 'string', 'in:test,live'],
            'allowed_origins' => ['nullable', 'array', 'max:50'],
            'allowed_origins.*' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $parts = is_string($value) ? parse_url($value) : false;
                if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
                    $fail('Each origin must be an HTTPS origin without a path, credentials, query, or fragment.');
                }
            }],
            'ip_allowlist' => ['nullable', 'array', 'max:50'],
            'ip_allowlist.*' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! (filter_var($value, FILTER_VALIDATE_IP) || IpUtils::checkIp('127.0.0.1', $value))) {
                    $fail('Each IP allowlist entry must be a valid IP address or CIDR range.');
                }
            }],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'in:availability.view,holds.create,holds.release,payments.create,reservations.create,reservations.view,reservations.cancel'],
            'hold_limit_per_key' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'hold_limit_per_ip' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'hold_limit_per_session' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $attributes = $this->publicApiKeyAttributes($request);
        $attributes['created_by'] = $request->user()?->id;

        try {
            $issued = $keys->issue($branch, $attributes);
        } catch (DomainException $exception) {
            return back()->withErrors(['public_api_key' => $exception->getMessage()]);
        }

        return redirect()->route('developers.index')
            ->with('public_api_secret', $issued['secret'])
            ->with('public_api_key_id', $issued['key']->id)
            ->with('toast', ['type' => 'success', 'message' => 'Public API key created. Copy the secret now; it will not be shown again.']);
    }

    public function rotatePublicKey(Request $request, Branch $branch, ApiKey $key, PublicApiKeyService $keys): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($key->branch_id === $branch->id, 404);
        $request->validate(['grace_hours' => ['nullable', 'integer', 'min:1', 'max:168']]);

        try {
            $rotated = $keys->rotate($key, $request->integer('grace_hours', 24), $request->user()?->id);
        } catch (DomainException $exception) {
            return back()->withErrors(['public_api_key' => $exception->getMessage()]);
        }

        return redirect()->route('developers.index')
            ->with('public_api_secret', $rotated['secret'])
            ->with('public_api_key_id', $rotated['key']->id)
            ->with('toast', ['type' => 'success', 'message' => 'Public API key rotated. The previous key remains valid through the grace period.']);
    }

    public function revokePublicKey(Request $request, Branch $branch, ApiKey $key, PublicApiKeyService $keys): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($key->branch_id === $branch->id, 404);

        $keys->revoke($key, $request->user()?->id);

        return redirect()->route('developers.index')
            ->with('toast', ['type' => 'success', 'message' => "Public API key {$key->name} revoked."]);
    }

    /** @return array<string, mixed> */
    private function publicApiKeyAttributes(Request $request): array
    {
        return [
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'environment' => $request->input('environment'),
            'allowed_origins' => $request->input('allowed_origins'),
            'ip_allowlist' => $request->input('ip_allowlist'),
            'scopes' => $request->input('scopes'),
            'hold_limit_per_key' => $request->input('hold_limit_per_key'),
            'hold_limit_per_ip' => $request->input('hold_limit_per_ip'),
            'hold_limit_per_session' => $request->input('hold_limit_per_session'),
        ];
    }

    private function ensureConsumerAccess(ApiConsumer $consumer): void
    {
        $user = request()->user();
        abort_unless($user !== null, 403);

        if ($user->is_global_admin) {
            return;
        }

        $branchIds = $consumer->reachableBranchIds();
        abort_if($branchIds === [], 404);

        foreach ($branchIds as $branchId) {
            $this->ensureBranchAccess(Branch::query()->find($branchId));
        }
    }
}
