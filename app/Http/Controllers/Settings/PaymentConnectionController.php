<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PaymentConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('settings/PaymentConnections', [
            'branch' => $branch->only(['id', 'name']),
            'connections' => $connections,
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
