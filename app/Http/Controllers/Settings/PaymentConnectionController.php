<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\PaymentConnection;
use App\Models\WebhookDelivery;
use App\Services\PaymentService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Hotel-owned payment gateway credentials. Secrets are write-only: the page
 * only learns whether a secret is stored, never its value.
 */
class PaymentConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $branch = $this->branch($request);

        $connections = PaymentConnection::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider,
                'mode' => $connection->mode,
                'status' => $connection->status,
                'is_default' => $connection->is_default,
                'scope' => $connection->branch_id === null ? 'all_properties' : 'this_property',
                'merchant_reference' => $connection->merchant_reference,
                'has_webhook_secret' => $connection->webhook_secret !== null,
                'webhook_url' => url("/webhooks/{$connection->provider}/{$connection->id}"),
            ])
            ->values();

        $lastDelivery = WebhookDelivery::query()
            ->whereIn('api_consumer_id', $this->webhookConsumerIds($branch))
            ->latest('id')
            ->first();

        $webhookLast = $lastDelivery === null ? null : [
            'event' => $lastDelivery->event,
            'status' => $lastDelivery->status,
            'attempts' => $lastDelivery->attempts,
            'last_error' => $lastDelivery->last_error,
            'delivered_at' => $lastDelivery->created_at?->toIso8601String(),
            'signature' => $lastDelivery->signature,
        ];

        return Inertia::render('settings/PaymentConnections', [
            'branch' => $branch->only(['id', 'name']),
            'connections' => $connections->map(fn (array $connection): array => [
                ...$connection,
                'last_webhook' => $webhookLast,
            ])->values(),
            'providers' => PaymentConnection::PROVIDERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'provider' => ['required', Rule::in(PaymentConnection::PROVIDERS)],
            'mode' => ['required', Rule::in(['test', 'live'])],
            'scope' => ['required', Rule::in(['this_property', 'all_properties'])],
            'secret_key' => ['required', 'string', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:512'],
            'merchant_reference' => ['nullable', 'string', 'max:128'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $branch, $request): void {
            $branchId = $data['scope'] === 'this_property' ? $branch->id : null;

            if ($request->boolean('is_default')) {
                $this->clearDefault($branch->tenant_id, $branchId);
            }

            PaymentConnection::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branchId,
                'provider' => $data['provider'],
                'mode' => $data['mode'],
                'name' => $data['name'],
                'secret_key' => $data['secret_key'],
                'webhook_secret' => $data['webhook_secret'] ?? null,
                'merchant_reference' => $data['merchant_reference'] ?? null,
                'is_default' => $request->boolean('is_default'),
                'status' => 'active',
            ]);
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'Payment connection added.']);
    }

    public function update(Request $request, PaymentConnection $connection): RedirectResponse
    {
        $this->ensureOwned($this->branch($request), $connection);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'is_default' => ['nullable', 'boolean'],
            'secret_key' => ['nullable', 'string', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:512'],
        ]);

        DB::transaction(function () use ($data, $connection, $request): void {
            $attributes = [];

            if (isset($data['status'])) {
                $attributes['status'] = $data['status'];
            }

            if (! empty($data['secret_key'])) {
                $attributes['secret_key'] = $data['secret_key'];
            }

            if (! empty($data['webhook_secret'])) {
                $attributes['webhook_secret'] = $data['webhook_secret'];
            }

            if ($request->has('is_default')) {
                if ($request->boolean('is_default')) {
                    $this->clearDefault($connection->tenant_id, $connection->branch_id);
                }

                $attributes['is_default'] = $request->boolean('is_default');
            }

            $connection->update($attributes);
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'Payment connection updated.']);
    }

    public function destroy(Request $request, PaymentConnection $connection): RedirectResponse
    {
        $this->ensureOwned($this->branch($request), $connection);

        if ($connection->transactions()->exists()) {
            return back()->with('toast', ['type' => 'error', 'message' => 'This connection has payments; disable it instead of deleting.']);
        }

        $connection->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Payment connection removed.']);
    }

    /**
     * Live BYOG diagnostic: pings the hotel's own gateway credentials
     * (stored key or a freshly provided one) and reports account status,
     * mode match and merchant identity.
     *
     * @return array<string, mixed>
     */
    public function test(Request $request, PaymentConnection $connection): JsonResponse
    {
        $branch = $this->branch($request);
        $this->ensureOwned($branch, $connection);

        if ($connection->status !== 'active') {
            return response()->json([
                'data' => [
                    'status' => 'inactive',
                    'provider' => $connection->provider,
                    'mode' => $connection->mode,
                    'merchant_name' => $connection->merchant_reference,
                    'reachable' => false,
                ],
            ], 200);
        }

        $secretKey = $connection->secret_key;
        if (is_string($request->input('secret_key')) && trim($request->input('secret_key')) !== '') {
            $secretKey = trim($request->input('secret_key'));
        }

        if (trim($secretKey) === '') {
            return response()->json([
                'data' => [
                    'status' => 'invalid',
                    'provider' => $connection->provider,
                    'mode' => $connection->mode,
                    'merchant_name' => null,
                    'reachable' => false,
                ],
            ], 422);
        }

        try {
            $service = app(PaymentService::class)->forSecret($connection->provider, $secretKey);
            $health = $service->driver()->healthCheck();
        } catch (RuntimeException $exception) {
            return response()->json([
                'data' => [
                    'status' => 'invalid',
                    'provider' => $connection->provider,
                    'mode' => $connection->mode,
                    'merchant_name' => null,
                    'reachable' => false,
                    'reason' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => $health]);
    }

    public function sendWebhookTest(Request $request, PaymentConnection $connection): JsonResponse
    {
        $branch = $this->branch($request);
        $this->ensureOwned($branch, $connection);

        try {
            $delivery = app(WebhookDispatcher::class)->dispatchTest('payment.test', [
                'connection' => [
                    'id' => $connection->id,
                    'provider' => $connection->provider,
                    'mode' => $connection->mode,
                ],
            ], (int) ($connection->branch_id ?? $branch->id));
        } catch (AvailabilityException $exception) {
            return response()->json(['data' => [
                'status' => 'failed',
                'event' => 'payment.test',
                'delivery_id' => null,
                'consumer_name' => null,
                'attempts' => 0,
                'last_error' => $exception->getMessage(),
            ]], 422);
        }

        return response()->json(['data' => [
            'status' => $delivery->status,
            'event' => 'payment.test',
            'delivery_id' => $delivery->id,
            'consumer_name' => $delivery->consumer?->name,
            'attempts' => $delivery->attempts,
            'last_error' => $delivery->last_error,
        ]]);
    }

    private function webhookConsumerIds(Branch $branch): array
    {
        $consumerIds = ApiConsumer::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        foreach (ApiConsumer::query()->whereNotNull('branch_ids')->get() as $consumer) {
            if ($consumer->is_active && in_array($branch->id, $consumer->reachableBranchIds(), true)) {
                $consumerIds[] = $consumer->id;
            }
        }

        return array_values(array_unique($consumerIds));
    }

    private function clearDefault(int $tenantId, ?int $branchId): void
    {
        PaymentConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function ensureOwned(Branch $branch, PaymentConnection $connection): void
    {
        abort_unless(
            $connection->tenant_id === $branch->tenant_id
            && ($connection->branch_id === null || $connection->branch_id === $branch->id),
            403,
        );
    }

    private function branch(Request $request): Branch
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch instanceof Branch, 404);

        return $branch;
    }
}
