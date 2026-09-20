<?php

namespace App\Support\TwoFactor;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The gap between "the password was right" and "you are signed in".
 *
 * Nothing in the session says `auth.web` until the second factor is answered, so a
 * half-finished sign-in is not a session with reduced privileges — it is not a
 * session at all. Only an id is parked here: the user is re-read on the way out, so
 * a factor disabled in the meantime is honoured.
 */
final class PendingLogin
{
    private const ID = 'auth.two_factor.id';

    private const REMEMBER = 'auth.two_factor.remember';

    public static function begin(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::ID, $user->getKey());
        $request->session()->put(self::REMEMBER, $remember);
    }

    public static function user(Request $request): ?User
    {
        $id = $request->session()->get(self::ID);

        return $id === null ? null : User::find($id);
    }

    public static function remember(Request $request): bool
    {
        return (bool) $request->session()->get(self::REMEMBER, false);
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget([self::ID, self::REMEMBER]);
    }
}
