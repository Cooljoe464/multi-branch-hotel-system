<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->is_global_admin) {
                return true;
            }

            return null;
        });
    }

    /**
     * Configure rate limiters for public and integration endpoints.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('booking', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('pos', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()->id ?? $request->ip());
        });

        RateLimiter::for('checkin', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()->id ?? $request->ip());
        });

        RateLimiter::for('folio', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()->id ?? $request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
