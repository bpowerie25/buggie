<?php

use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\EnsureRegistrationIsOpen;
use App\Http\Middleware\EnsureWorkspaceMember;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireHostedMode;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every web request: binds the tenant and turns on strict mode,
        // so a tenant query with no workspace resolved throws instead of leaking.
        $middleware->web(append: [
            // Remembers the password hash in each session and ends any session whose
            // hash no longer matches: resetting a password signs out every other
            // browser, including one somebody else was using.
            SecurityHeaders::class,
            AuthenticateSession::class,
            ResolveWorkspace::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // An authenticated visitor hitting /login or /register is sent to the central
        // home, which routes them on to their last workspace.
        //
        // Without this, Laravel looks for a route named 'dashboard', finds ours on the
        // {workspace} subdomain, and throws for a missing parameter — a 500 on a page
        // anyone might reload out of habit.
        $middleware->redirectUsersTo('/');

        // A guest turned away from a workspace keeps the workspace, so the sign-in
        // page can offer to ask it for access. The tenant is already resolved: see
        // the priority list below.
        $middleware->redirectGuestsTo(function () {
            $workspace = app(Tenancy::class)->current();

            return central_url($workspace ? 'login?workspace='.$workspace->slug : 'login');
        });

        // Behind a reverse proxy, which is how this is deployed and how most
        // self-hosters will run it. Without this Laravel never sees
        // X-Forwarded-Proto, generates every asset URL as http:// on an https://
        // page, and the browser blocks them as mixed content — which presents as a
        // blank white page with a working 200 response and nothing in the log.
        //
        // Private ranges only, not '*': the self-host compose file publishes the
        // application's port directly, so on that setup a visitor could otherwise
        // spoof the header themselves. Override with TRUSTED_PROXIES if a load
        // balancer sits on a public address.
        $middleware->trustProxies(at: array_map('trim', explode(',', (string) env(
            'TRUSTED_PROXIES',
            '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1',
        ))));

        $middleware->alias([
            'workspace' => EnsureWorkspaceMember::class,
            'hosted' => RequireHostedMode::class,
            'registration' => EnsureRegistrationIsOpen::class,

            // Sanctum ships these but registers no aliases, so the API's
            // `abilities:read` would otherwise resolve as a class name.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // The tenant must resolve before auth (an unknown subdomain is a 404, not a
        // redirect to login) and before SubstituteBindings (ResolveWorkspace drops the
        // {workspace} domain parameter so controllers never have to accept it).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveWorkspace::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Opt-in: with no SENTRY_LARAVEL_DSN set this does nothing, so a self-hosted
        // install never reports anything to anybody.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
