<?php

namespace App\Providers;

use App\Contracts\EInvoiceEmitter;
use App\Events\FolioClosed;
use App\Listeners\QueueEinvoiceForClosedFolio;
use App\Models\User;
use App\Services\ConciergeAiManager;
use App\Services\Fiscal\FirsEmitter;
use App\Support\TenantDatabaseContext;
use App\Support\TenantQueueContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
        $this->app->singleton(TenantQueueContext::class);
        $this->app->bind(EInvoiceEmitter::class, FirsEmitter::class);
        $this->app->singleton(ConciergeAiManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureFiscalEmission();
        $this->configureTenantQueueIsolation();

        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->is_global_admin) {
                return true;
            }

            return null;
        });
    }

    /**
     * Settled folios queue their FIRS e-invoice exactly once.
     */
    protected function configureFiscalEmission(): void
    {
        Event::listen(FolioClosed::class, QueueEinvoiceForClosedFolio::class);
    }

    protected function configureTenantQueueIsolation(): void
    {
        static $payloadHookRegistered = false;

        if (! $payloadHookRegistered) {
            Queue::createPayloadUsing(function (): array {
                $tenantContext = app(TenantDatabaseContext::class)->captureQueueContext();

                return $tenantContext === null ? [] : ['tenant_context' => $tenantContext];
            });

            $payloadHookRegistered = true;
        }

        Queue::before(function (JobProcessing $event): void {
            app(TenantQueueContext::class)->begin($event->job);
        });

        Queue::after(function (JobProcessed $event): void {
            app(TenantQueueContext::class)->commit($event->job);
        });

        Queue::exceptionOccurred(function (JobExceptionOccurred $event): void {
            app(TenantQueueContext::class)->rollback($event->job);
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
