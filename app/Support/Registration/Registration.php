<?php

namespace App\Support\Registration;

use App\Enums\RegistrationMode;
use App\Models\User;
use App\Support\Invitations\PendingInvitation;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Who may register, and who may create a workspace, on this install.
 *
 * One place, because there are several doors — the registration form, the
 * workspace form, the action behind it, the links that lead to them — and a rule
 * that lives in each of them separately is a rule that one of them forgets.
 */
class Registration
{
    public const SETTING = 'registration.mode';

    /** Written once, by whichever registration takes the first-run exception. */
    public const FIRST_RUN_CLAIM = 'registration.first_run_claimed';

    public function __construct(private Settings $settings) {}

    /**
     * The environment first, then the screen, then the default for this kind of
     * install. An install upgraded from before this existed has nothing stored, so it
     * lands on the default — which, self-hosted, closes open sign-up.
     */
    public function mode(): RegistrationMode
    {
        if (($value = $this->environmentValue()) !== null) {
            return RegistrationMode::tryFrom($value) ?? RegistrationMode::Invite;
        }

        return RegistrationMode::tryFrom((string) $this->settings->get(self::SETTING))
            ?? $this->default();
    }

    public function default(): RegistrationMode
    {
        return config('buggie.hosted') ? RegistrationMode::Open : RegistrationMode::Invite;
    }

    /** BUGGIE_REGISTRATION as written, or null when it is not set. */
    public function environmentValue(): ?string
    {
        $value = strtolower(trim((string) config('buggie.registration')));

        return $value === '' ? null : $value;
    }

    public function isSetByEnvironment(): bool
    {
        return $this->environmentValue() !== null;
    }

    public function set(RegistrationMode $mode): void
    {
        $this->settings->put([self::SETTING => $mode->value]);
    }

    /**
     * Nobody has an account yet, so somebody has to be allowed to make the first.
     *
     * Read from the table rather than the settings cache, which is shared across
     * requests and would be the wrong thing to trust on the one question two
     * visitors may be racing to answer.
     */
    public function isFirstRun(): bool
    {
        return ! User::query()->exists()
            && ! DB::table('app_settings')->where('key', self::FIRST_RUN_CLAIM)->exists();
    }

    /**
     * Take the first-run exception, or learn that somebody else already has.
     *
     * Call inside the transaction that creates the account. The key is the table's
     * primary key, so of two registrations racing on an empty install, Postgres makes
     * the second wait on the first's insert and then do nothing: exactly one gets a
     * row back. If the winner's transaction fails, its claim rolls back with it and
     * the exception is still there to be taken.
     *
     * Never cleared. An install whose accounts have all been deleted does not reopen
     * to the next stranger; `buggie:operator` is the way back in.
     */
    public function claimFirstRun(): bool
    {
        return DB::table('app_settings')->insertOrIgnore([
            'key' => self::FIRST_RUN_CLAIM,
            'value' => json_encode(now()->toIso8601String()),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * Why this visitor may register, or null if they may not.
     *
     * An invitation counts because it is how people are meant to arrive: following
     * one while signed out sends a newcomer here, carrying the token in the session.
     */
    public function admits(Request $request): ?Admission
    {
        if ($this->isFirstRun()) {
            return Admission::FirstRun;
        }

        return $this->admitsOtherwise($request);
    }

    /** As admits(), for somebody who has just lost the race for the first run. */
    public function admitsOtherwise(Request $request): ?Admission
    {
        if ($this->mode()->allowsAnyoneToRegister()) {
            return Admission::Open;
        }

        if (PendingInvitation::destinationFor($request) !== null) {
            return Admission::Invitation;
        }

        return null;
    }

    public function mayCreateWorkspace(User $user): bool
    {
        return $this->mode()->allowsAnyoneToRegister()
            || Gate::forUser($user)->allows('operate');
    }
}
