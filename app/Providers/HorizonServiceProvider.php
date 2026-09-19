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
        // Who operates this install, as opposed to who owns a workspace in it.
        //
        // On the hosted service that is whoever is named in BUGGIE_OPERATORS, and an
        // empty list means nobody — failing closed. A self-hosted install with nobody
        // named falls back to the first account created: it is somebody's own server,
        // and making them edit .env before they can configure mail is exactly the
        // friction worth removing.
        Gate::define('operate', function (?\App\Models\User $user = null) {
            if ($user === null) {
                return false;
            }

            $operators = array_map('strtolower', (array) config('buggie.operators'));

            if ($operators !== []) {
                return in_array(strtolower($user->email), $operators, true);
            }

            return ! config('buggie.hosted')
                && $user->id === \App\Models\User::query()->min('id');
        });

        Gate::define('viewHorizon', function ($user = null) {
            $operators = (array) config('buggie.operators');

            // No operators configured means nobody gets in, rather than everybody.
            return $user !== null
                && $operators !== []
                && in_array(strtolower($user->email), array_map('strtolower', $operators), true);
        });
    }
}
