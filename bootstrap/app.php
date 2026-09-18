<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
            \App\Http\Middleware\ResolveWorkspace::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'workspace' => \App\Http\Middleware\EnsureWorkspaceMember::class,
            'hosted' => \App\Http\Middleware\RequireHostedMode::class,
        ]);

        // The tenant must resolve before auth (an unknown subdomain is a 404, not a
        // redirect to login) and before SubstituteBindings (ResolveWorkspace drops the
        // {workspace} domain parameter so controllers never have to accept it).
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\ResolveWorkspace::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Opt-in: with no SENTRY_LARAVEL_DSN set this does nothing, so a self-hosted
        // install never reports anything to anybody.
        \Sentry\Laravel\Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
