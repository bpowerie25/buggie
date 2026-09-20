<?php

namespace App\Http\Controllers;

use App\Support\TwoFactor\QrCode;
use App\Support\TwoFactor\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Turning a second factor on and off, for your own account.
 *
 * Yours, not the workspace's — the same reason notification preferences are. An
 * account belongs to a person who may work in four workspaces, and a factor set up
 * in one of them protects all four.
 *
 * There is no plan check anywhere in this file, and there should never be one. A
 * paywalled second factor is a charge for not being broken into, and the people who
 * would decline to pay it are exactly the ones whose workspace is a side project
 * with a reused password.
 */
class TwoFactorController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_remaining' => $user->recoveryCodesRemaining(),

            /*
             * The secret leaves the server exactly once: while it is still unproved
             * and somebody is looking at the QR code. After confirmation this is null
             * for ever, so reloading the settings page does not put the key back on
             * screen for whoever is standing behind it.
             */
            'pending' => $user->hasTwoFactorPending() ? [
                'qr' => QrCode::svgDataUri($user->twoFactorUri()),
                'secret' => Totp::readable($user->two_factor_secret),
            ] : null,

            // Stored hashed, so this one render is the only chance to read them.
            'recovery_codes' => session('two_factor_recovery_codes'),
        ]);
    }

    /** Make a secret and show it. Nothing is switched on by this. */
    public function store(Request $request): RedirectResponse
    {
        $request->user()->startTwoFactorEnrolment();

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $codes = $request->user()->confirmTwoFactor((string) $request->input('code'));

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Check the time on the phone, then try the next one.',
            ]);
        }

        return back()
            ->with('two_factor_recovery_codes', $codes)
            ->with('success', 'Two-factor authentication is on. Save the recovery codes below.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->hasTwoFactorEnabled(), 404);

        return back()
            ->with('two_factor_recovery_codes', $user->replaceRecoveryCodes())
            ->with('success', 'New recovery codes. The previous set no longer works.');
    }

    /**
     * Switching it off asks for proof, because the session alone is not proof.
     *
     * A borrowed laptop, an unlocked screen or a stolen cookie is precisely the
     * situation a second factor exists for, and a switch that turns it off with one
     * click hands the attacker the same account they were being kept out of.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string'],
        ]);

        $code = (string) $request->input('code', '');
        $password = (string) $request->input('password', '');

        $proved = ($code !== '' && ($user->acceptTwoFactorCode($code) || $user->consumeRecoveryCode($code)))
            || ($password !== '' && Hash::check($password, $user->password));

        if (! $proved) {
            throw ValidationException::withMessages([
                'code' => 'Enter a current code or your password to turn this off.',
            ]);
        }

        $user->disableTwoFactor();

        return back()->with('success', 'Two-factor authentication is off.');
    }
}
