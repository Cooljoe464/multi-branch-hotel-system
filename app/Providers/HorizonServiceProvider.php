<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        $notifyEmail = config('backup.notifications.mail.to');

        if (is_string($notifyEmail) && $notifyEmail !== '' && $notifyEmail !== 'admin@example.com') {
            Horizon::routeMailNotificationsTo($notifyEmail);
        }

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?object $user = null) {
            if (app()->isLocal()) {
                return true;
            }

            if (! $user instanceof User) {
                return false;
            }

            return $user->hasRole('Global Admin');
        });
    }
}
