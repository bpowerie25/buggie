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
