<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppController extends Controller
{
    public function edit(Request $request): Response
    {
        $branch = $this->branch($request);
        $service = new WhatsAppCloudApiService;
        $whatsapp = $service->settings($branch);

        return Inertia::render('settings/WhatsApp', [
            'branch' => $branch->only(['id', 'name']),
            'whatsapp' => [
                'enabled' => ($whatsapp['enabled'] ?? false) === true,
                'phone_number_id' => $whatsapp['phone_number_id'] ?? '',
                'waba_id' => $whatsapp['waba_id'] ?? '',
                'verify_token' => $whatsapp['verify_token'] ?? '',
                'has_access_token' => $service->accessToken($branch) !== null,
                'has_app_secret' => $service->appSecret($branch) !== null,
            ],
            'webhook_url' => url('/webhooks/whatsapp'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);

        $request->validate([
            'enabled' => 'nullable|boolean',
            'phone_number_id' => 'nullable|string|max:64',
            'waba_id' => 'nullable|string|max:64',
            'verify_token' => 'nullable|string|max:128',
            'access_token' => 'nullable|string|max:2048',
            'app_secret' => 'nullable|string|max:256',
            'clear_access_token' => 'nullable|boolean',
            'clear_app_secret' => 'nullable|boolean',
        ]);

        $settings = $branch->settings ?? [];
        $whatsapp = is_array($settings['whatsapp'] ?? null) ? $settings['whatsapp'] : [];

        $whatsapp['enabled'] = $request->boolean('enabled');
        $whatsapp['phone_number_id'] = $request->string('phone_number_id')->trim()->value() ?: null;
        $whatsapp['waba_id'] = $request->string('waba_id')->trim()->value() ?: null;
        $whatsapp['verify_token'] = $request->string('verify_token')->trim()->value() ?: null;

        $settings['whatsapp'] = $whatsapp;
        $branch->update(['settings' => $settings]);

        $service = new WhatsAppCloudApiService;
        $accessToken = $request->string('access_token')->trim()->value();
        $appSecret = $request->string('app_secret')->trim()->value();

        if ($request->boolean('clear_access_token')) {
            $service->storeAccessToken($branch, null);
        } elseif ($accessToken !== '') {
            $service->storeAccessToken($branch, $accessToken, rotate: true);
        }

        if ($request->boolean('clear_app_secret')) {
            $service->storeAppSecret($branch, null);
        } elseif ($appSecret !== '') {
            $service->storeAppSecret($branch, $appSecret, rotate: true);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'WhatsApp Cloud API settings saved.']);
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
