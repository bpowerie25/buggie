<?php

namespace App\Support\TwoFactor;

/**
 * The way back in when the phone is gone.
 *
 * Without these, a second factor is a way of locking yourself out of your own
 * account, and the only remedy is somebody with database access — which a
 * self-hosted install has and a hosted customer does not.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    /**
     * No I, O, 0 or 1. These are read off a printout and typed by somebody who has
     * just lost their phone, and that is the worst possible moment to discover that
     * a character was ambiguous.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 10;

    /**
     * Fresh codes, in the clear. This is the only time they exist in readable form.
     *
     * @return array<int, string>
     */
    public static function generate(int $count = self::COUNT): array
    {
        return array_map(fn () => self::one(), range(1, $count));
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public static function hashAll(array $codes): array
    {
        return array_map(self::hash(...), $codes);
    }

    /**
     * A plain SHA-256, not bcrypt.
     *
     * The usual argument for a slow hash is that people choose weak secrets. Nobody
     * chose these: fifty bits from the system's random source, so there is nothing
     * for a dictionary to try and no work factor that improves on that. Bcrypt would
     * only mean eight expensive comparisons on every sign-in that uses one.
     */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalise($code));
    }

    /**
     * Does this code match one of the stored hashes, and which?
     *
     * Returns the index so the caller can remove exactly the one that was used. A
     * recovery code that survives its own use is a password with extra steps.
     *
     * @param  array<int, string>  $hashes
     */
    public static function match(string $code, array $hashes): ?int
    {
        $candidate = self::hash($code);
        $found = null;

        foreach ($hashes as $index => $hash) {
            if (hash_equals((string) $hash, $candidate)) {
                $found = $index;
            }
        }

        return $found;
    }

    /** Case and the dash are presentation; somebody typing this in a hurry is not. */
    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private static function one(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        // Hyphenated for reading and transcription only — normalise() takes it back out.
        return substr($code, 0, 5).'-'.substr($code, 5);
    }
}
