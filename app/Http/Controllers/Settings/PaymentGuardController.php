<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentGuardController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch instanceof Branch, 404);

        $settings = $branch->settings ?? [];
        $currentMode = $settings['payment_guard_mode'] ?? 'pay_first';

        return Inertia::render('settings/PaymentGuard', [
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
            'currentMode' => $currentMode,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch instanceof Branch, 404);

        $request->validate([
            'payment_guard_mode' => 'required|string|in:pay_first,pay_after',
        ]);

        $settings = $branch->settings ?? [];
        $settings['payment_guard_mode'] = $request->string('payment_guard_mode')->value();

        $branch->update(['settings' => $settings]);

        return $this->flashSuccess('Payment guard policy updated.');
    }
}
