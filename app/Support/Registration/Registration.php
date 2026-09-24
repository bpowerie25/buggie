<?php

namespace App\Support\Registration;

use App\Enums\RegistrationMode;
use App\Models\User;
use App\Support\Invitations\PendingInvitation;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
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

    /** Nobody has an account yet, so somebody has to be allowed to make the first. */
    public function isFirstRun(): bool
    {
        return ! User::query()->exists();
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
