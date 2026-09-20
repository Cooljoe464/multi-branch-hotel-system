<?php

namespace App\Http\Middleware;

use App\Models\Branding;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => $this->resolveAppName(),
            'branding' => $this->resolveBrandingData(),
            'auth' => [
                'user' => $request->user(),
                'roles' => $request->user()?->getRoleNames()->values()->all() ?? [],
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            'branch' => $this->resolveBranchData($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function resolveAppName(): string
    {
        try {
            $branding = Branding::instance();
            $appName = $branding->app_name;
        } catch (\Throwable) {
            $appName = config('app.name');
        }

        return is_string($appName) ? $appName : 'Laravel';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBrandingData(): array
    {
        try {
            $branding = Branding::instance();

            return [
                'app_name' => $branding->app_name,
                'logo_url' => $branding->logo_url,
            ];
        } catch (\Throwable) {
            return [
                'app_name' => config('app.name', 'Laravel'),
                'logo_url' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBranchData(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $currentBranch = $request->get('_branch') ?? $user->currentBranch;

        if (! $currentBranch) {
            return null;
        }

        $branding = Branding::instance();

        $currencyCode = $currentBranch->currency_code ?: $branding->currency_code;
        $currencySymbol = $currentBranch->currency_symbol ?: $branding->currency_symbol;

        return [
            'current' => [
                ...$currentBranch->toArray(),
                'currency_code' => $currencyCode,
                'currency_symbol' => $currencySymbol,
            ],
            'available' => $user->branches()->where('is_active', true)->get(),
            'can_switch' => $user->hasRole('Global Admin') || $user->branches()->count() > 1,
        ];
    }
}
