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
        // Who operates this install, as opposed to who owns a workspace in it:
        // whoever is named in BUGGIE_OPERATORS, and whoever is marked as one.
        //
        // Marked by `buggie:operator`, or by being the account that registered first
        // on a self-hosted install — it is somebody's own server, and making them edit
        // .env before they can configure mail is exactly the friction worth removing.
        // Nothing marks anybody on the hosted service except that command, so there
        // an empty list still means nobody.
        //
        // This used to be "the lowest user id" whenever nobody was named, worked out
        // on every check. Deleting that account would have promoted the next one.
        Gate::define('operate', function (?User $user = null) {
            if ($user === null) {
                return false;
            }

            // Named by address only once the address is confirmed: anybody can register
            // an address that is not theirs, and on an install where the operator
            // listed has not signed up yet that would make them the operator.
            return $user->is_operator
                || ($user->hasVerifiedEmail() && in_array(
                    strtolower($user->email),
                    array_map('strtolower', (array) config('buggie.operators')),
                    true,
                ));
        });

        Gate::define('viewHorizon', function ($user = null) {
            $operators = (array) config('buggie.operators');

            // No operators configured means nobody gets in, rather than everybody.
            return $user !== null
                && $operators !== []
                && $user->hasVerifiedEmail()
                && in_array(strtolower($user->email), array_map('strtolower', $operators), true);
        });
    }
}
