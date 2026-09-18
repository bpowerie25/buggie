<?php

namespace App\Support\Invitations;

use App\Models\Invitation;
use Illuminate\Http\Request;

/**
 * Carries an invitation across signing up or signing in.
 *
 * Accepting an invitation needs an authenticated user, so a visitor who follows the
 * link without an account is sent away to make one. Something has to remember why
 * they left, or they arrive back at "create a workspace", most likely create a stray
 * workspace of their own, and never find the one they were invited to — with the only
 * way out being to dig the original email back up and click the link a second time.
 *
 * The token rides in the session rather than the URL: session cookies are set on
 * `.buggie.eu`, so they survive the hop from the workspace subdomain to the central
 * domain and back, and an invitation token in a query string ends up in browser
 * history and referrer headers.
 */
class PendingInvitation
{
    public const KEY = 'invitation_token';

    public static function remember(Request $request, string $token): void
    {
        $request->session()->put(self::KEY, $token);
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }

    /**
     * Where to send someone who has just authenticated, or null if no invitation is
     * waiting for them.
     *
     * Re-checked rather than trusted: an invitation can be revoked or accepted by
     * somebody else between the redirect out and the return, and a stale token should
     * quietly fall back to the normal destination rather than 404 a new user on their
     * very first page.
     */
    public static function destinationFor(Request $request): ?string
    {
        $token = $request->session()->get(self::KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $invitation = Invitation::withoutGlobalScopes()
            ->with('workspace')
            ->where('token', $token)
            ->first();

        if ($invitation === null || ! $invitation->isPending() || $invitation->workspace === null) {
            self::forget($request);

            return null;
        }

        return workspace_url($invitation->workspace->slug, "invitations/{$token}");
    }
}
