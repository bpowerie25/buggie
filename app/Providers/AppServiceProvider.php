<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\Workspace;
use App\Support\Settings\MailConfiguration;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant per request/job. Everything workspace-scoped reads from here.
        $this->app->scoped(Tenancy::class);

        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // A new sign-in starts from its own password, not whatever the session last
        // remembered. AuthenticateSession keeps a password hash in the session and
        // ends any session it no longer matches; a sign-in after somebody else's in
        // the same browser would otherwise be measured against theirs and thrown out.
        Event::listen(
            Login::class,
            fn (Login $event) => request()->hasSession()
                ? request()->session()->forget('password_hash_'.$event->guard)
                : null,
        );

        $this->app->make(MailConfiguration::class)->apply();

        // Workspaces pay, not users: one person may belong to several workspaces and
        // only one of them may be subscribed.
        Cashier::useCustomerModel(Workspace::class);
        Cashier::calculateTaxes();

        // Built explicitly rather than by route(): the notification may be sent from
        // a queued job with no request behind it, and workspaces live on subdomains,
        // so the link has to be pinned to the central domain.
        ResetPassword::createUrlUsing(fn ($user, string $token) => central_url(
            'reset-password/'.$token.'?email='.urlencode($user->getEmailForPasswordReset()),
        ));

        // Tokens carry a workspace, so Sanctum is told to use ours.
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // Per token, not per IP: one noisy script should not throttle a colleague
        // working from the same office. Falls back to the address for anything
        // unauthenticated, which should not reach these routes anyway.
        RateLimiter::for(
            'api',
            fn (Request $request) => Limit::perMinute(120)
                ->by($request->user()?->currentAccessToken()?->getKey() ?: $request->ip()),
        );

        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
