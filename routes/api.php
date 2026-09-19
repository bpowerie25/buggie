<?php

use App\Http\Controllers\Api\InboundMailController;
use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Widget ingest
|--------------------------------------------------------------------------
|
| Unauthenticated and cross-origin by necessity: the public key sits in the page
| source of the customer's application. Guarded by an origin allowlist, rate limits
| and hard size caps rather than by a credential. Lives on the central domain, so the
| key alone identifies the tenant.
|
*/

Route::post('ingest/{publicKey}', [IngestController::class, 'store'])
    ->name('ingest.store');

Route::post('ingest/screenshot/{report}', [IngestController::class, 'screenshot'])
    ->middleware('signed')
    ->name('ingest.screenshot');

/*
|--------------------------------------------------------------------------
| Inbound mail
|--------------------------------------------------------------------------
|
| Mailgun's inbound route webhook. Verified by signature rather than by a secret URL,
| and the routing token in the recipient address selects the tenant.
|
*/

Route::post('mail/inbound', InboundMailController::class)->name('mail.inbound');

/*
|--------------------------------------------------------------------------
| The public API
|--------------------------------------------------------------------------
|
| Versioned from the start, on the workspace subdomain, authenticated by a personal
| access token scoped to that workspace:
|
|   curl -H "Authorization: Bearer buggie_..." https://acme.buggie.eu/api/v1/issues
|
| Deliberately reuses the application's policies, form requests and actions. An API
| with its own idea of what is allowed eventually disagrees with the screens, and
| that disagreement is a security bug rather than an inconsistency.
|
*/

// host, not domain: the workspace group in routes/web.php uses the same, and
// `api` is already prefixed onto this file by the router.
Route::domain('{workspace}.'.config('buggie.host'))
    ->prefix('v1')
    ->middleware([
        // First: everything below needs a resolved tenant, and ResolveWorkspace is
        // appended to the `web` group, which these routes are not in.
        \App\Http\Middleware\ResolveWorkspace::class,
        'auth:sanctum',
        \App\Http\Middleware\EnsureTokenMatchesWorkspace::class,
        // Per token rather than per IP: one noisy script should not throttle a
        // colleague working from the same office.
        'throttle:api',
    ])
    ->group(function () {
        Route::middleware('abilities:read')->group(function () {
            Route::get('issues', [\App\Http\Controllers\Api\V1\IssueApiController::class, 'index']);
            Route::get('issues/{issue}', [\App\Http\Controllers\Api\V1\IssueApiController::class, 'show']);
            Route::get('projects', [\App\Http\Controllers\Api\V1\ProjectApiController::class, 'index']);
        });

        Route::middleware('abilities:write')->group(function () {
            Route::post('issues', [\App\Http\Controllers\Api\V1\IssueApiController::class, 'store']);
            Route::patch('issues/{issue}', [\App\Http\Controllers\Api\V1\IssueApiController::class, 'update']);
        });
    });
