<?php

namespace App\Models\Concerns;

use App\Support\TwoFactor\RecoveryCodes;
use App\Support\TwoFactor\Totp;
use Illuminate\Support\Facades\DB;

/**
 * A second factor on the account.
 *
 * Enrolment is deliberately two steps. The secret is written first and
 * `two_factor_confirmed_at` only once a code generated from it has been accepted, so
 * a secret that was scanned wrong, scanned into the wrong app, or never scanned at
 * all locks nobody out — it is an unfinished setup, not a closed door. Everything
 * that gates a sign-in asks whether the account is *confirmed*.
 */
trait HasTwoFactorAuthentication
{
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** A secret waiting to be proved: the QR has been shown, no code has arrived yet. */
    public function hasTwoFactorPending(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }

    /**
     * Start again from a new secret.
     *
     * Everything else is cleared with it. Recovery codes belong to a particular
     * secret, and a replay guard for one is meaningless against another.
     */
    public function startTwoFactorEnrolment(): string
    {
        $this->two_factor_secret = Totp::secret();
        $this->two_factor_recovery_codes = null;
        $this->two_factor_confirmed_at = null;
        $this->two_factor_last_step = null;
        $this->save();

        return $this->two_factor_secret;
    }

    /**
     * Switch it on, but only on proof that the app and the server agree.
     *
     * @return array<int, string>|null the recovery codes, in the clear and only now
     */
    public function confirmTwoFactor(string $code): ?array
    {
        if (! $this->hasTwoFactorPending() || ! $this->acceptTwoFactorCode($code)) {
            return null;
        }

        $this->two_factor_confirmed_at = now();
        $this->save();

        return $this->replaceRecoveryCodes();
    }

    public function disableTwoFactor(): void
    {
        $this->two_factor_secret = null;
        $this->two_factor_recovery_codes = null;
        $this->two_factor_confirmed_at = null;
        $this->two_factor_last_step = null;
        $this->save();
    }

    /**
     * New codes, and the old ones dead the moment these are shown.
     *
     * @return array<int, string>
     */
    public function replaceRecoveryCodes(): array
    {
        $codes = RecoveryCodes::generate();

        $this->two_factor_recovery_codes = RecoveryCodes::hashAll($codes);
        $this->save();

        return $codes;
    }

    public function recoveryCodesRemaining(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    /**
     * Accept a TOTP code once, and once only.
     *
     * The step is written with a condition rather than read, compared and then
     * written, because the read-then-write version loses to two requests arriving
     * together — which is exactly the shape of a replay. The database decides, and
     * only one of them gets the row.
     */
    public function acceptTwoFactorCode(string $code): bool
    {
        if ($this->two_factor_secret === null) {
            return false;
        }

        $step = Totp::verify($this->two_factor_secret, $code);

        if ($step === null) {
            return false;
        }

        $accepted = static::query()
            ->whereKey($this->getKey())
            ->where(fn ($query) => $query
                ->whereNull('two_factor_last_step')
                ->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]);

        if ($accepted === 1) {
            $this->two_factor_last_step = $step;
        }

        return $accepted === 1;
    }

    /**
     * Spend one recovery code.
     *
     * Locked and re-read inside the transaction: a code that can be used twice
     * because two requests raced is a code that is not single-use.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $consumed = DB::transaction(function () use ($code) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            $hashes = $locked->two_factor_recovery_codes ?? [];
            $index = RecoveryCodes::match($code, $hashes);

            if ($index === null) {
                return false;
            }

            unset($hashes[$index]);

            $locked->two_factor_recovery_codes = array_values($hashes);
            $locked->save();

            return true;
        });

        if ($consumed) {
            $this->refresh();
        }

        return $consumed;
    }

    /** What the QR code says, and what somebody typing it in by hand is typing. */
    public function twoFactorUri(): string
    {
        return Totp::uri($this->two_factor_secret ?? '', $this->email, config('app.name'));
    }
}
