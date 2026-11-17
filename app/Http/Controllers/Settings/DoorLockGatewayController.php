<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DoorLockGateway;
use App\Services\DoorLock\DoorLockService;
use App\Services\EntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One door-lock gateway per property. Credentials go to the branch secret
 * store; the page only learns whether each credential is stored.
 */
class DoorLockGatewayController extends Controller
{
    public function edit(Request $request): Response
    {
        $branch = $this->branch($request);
        $gateway = DoorLockGateway::forBranch($branch->id)->first();
        $settings = is_array($gateway?->settings) ? $gateway->settings : [];
        $entitlements = app(EntitlementService::class);

        return Inertia::render('settings/DoorLockGateway', [
            'branch' => $branch->only(['id', 'name']),
            'gateway' => $gateway === null ? null : [
                'provider' => $gateway->provider,
                'api_base_url' => $gateway->api_base_url,
                'is_active' => $gateway->is_active,
                'manual_mode' => ($settings['manual_mode'] ?? false) === true,
                'lock_id' => is_string($settings['lock_id'] ?? null) ? $settings['lock_id'] : '',
                'client_id' => $gateway->clientId() ?? '',
                'has_api_key' => $gateway->apiKey() !== null,
                'has_api_secret' => $gateway->apiSecret() !== null,
            ],
            'providers' => collect(DoorLockService::providerKeys())
                ->map(fn (string $key): array => [
                    'key' => $key,
                    'entitled' => $entitlements->allows($branch->tenant_id, 'locks.provider.'.$key),
                ])
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);

        $data = $request->validate([
            'provider' => ['required', Rule::in(DoorLockService::providerKeys())],
            'api_base_url' => ['required', 'url:https', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'manual_mode' => ['nullable', 'boolean'],
            'lock_id' => ['nullable', 'string', 'max:128'],
            'client_id' => ['nullable', 'string', 'max:128'],
            'api_key' => ['nullable', 'string', 'max:2048'],
            'api_secret' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! app(EntitlementService::class)->allows($branch->tenant_id, 'locks.provider.'.$data['provider'])) {
            throw ValidationException::withMessages([
                'provider' => 'The subscription does not include this lock provider.',
            ]);
        }

        DB::transaction(function () use ($data, $branch, $request): void {
            $gateway = DoorLockGateway::withTrashed()->forBranch($branch->id)->first() ?? new DoorLockGateway(['branch_id' => $branch->id, 'api_key' => '']);

            if ($gateway->trashed()) {
                $gateway->restore();
            }

            $settings = is_array($gateway->settings) ? $gateway->settings : [];
            $settings['manual_mode'] = $request->boolean('manual_mode');
            $settings['lock_id'] = $data['lock_id'] ?? null;
            $settings['client_id'] = $data['client_id'] ?? null;

            $gateway->fill([
                'provider' => $data['provider'],
                'api_base_url' => $data['api_base_url'],
                'is_active' => $request->boolean('is_active', true),
                'settings' => $settings,
            ])->save();

            if (! empty($data['api_key'])) {
                $gateway->storeApiKey($data['api_key'], rotate: $gateway->apiKey() !== null);
            }

            if (! empty($data['api_secret'])) {
                $gateway->storeApiSecret($data['api_secret'], rotate: $gateway->apiSecret() !== null);
            }
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'Door lock gateway saved.']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);

        DoorLockGateway::forBranch($branch->id)->first()?->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Door lock gateway removed.']);
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
