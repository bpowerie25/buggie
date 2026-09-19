<?php

namespace App\Providers;

use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant per request/job. Everything workspace-scoped reads from here.
        $this->app->scoped(Tenancy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Workspaces pay, not users: one person may belong to several workspaces and
        // only one of them may be subscribed.
        Cashier::useCustomerModel(\App\Models\Workspace::class);
        Cashier::calculateTaxes();

        // Built explicitly rather than by route(): the notification may be sent from
        // a queued job with no request behind it, and workspaces live on subdomains,
        // so the link has to be pinned to the central domain.
        ResetPassword::createUrlUsing(fn ($user, string $token) => central_url(
            'reset-password/'.$token.'?email='.urlencode($user->getEmailForPasswordReset()),
        ));

        // Tokens carry a workspace, so Sanctum is told to use ours.
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\ApiToken::class);

        // Per token, not per IP: one noisy script should not throttle a colleague
        // working from the same office. Falls back to the address for anything
        // unauthenticated, which should not reach these routes anyway.
        \Illuminate\Support\Facades\RateLimiter::for(
            'api',
            fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)
                ->by($request->user()?->currentAccessToken()?->getKey() ?: $request->ip()),
        );

        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
