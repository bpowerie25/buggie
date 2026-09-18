<?php

namespace App\Providers;

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

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    /**
     * Horizon shows jobs from every workspace, so this is deliberately not a
     * workspace role check — it is a list of the people who operate the install.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            $operators = (array) config('buggy.operators');

            // No operators configured means nobody gets in, rather than everybody.
            return $user !== null
                && $operators !== []
                && in_array(strtolower($user->email), array_map('strtolower', $operators), true);
        });
    }
}
