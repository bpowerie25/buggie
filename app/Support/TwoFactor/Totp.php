<?php

namespace App\Support\TwoFactor;

/**
 * Time-based one-time passwords, RFC 6238 over RFC 4226.
 *
 * Written out rather than pulled in. The algorithm is thirty lines of HMAC and a
 * truncation, it is frozen by the RFC, and the authenticator apps on the other end
 * are only interoperable because nobody gets to be creative with it. The unit test
 * runs the RFC's own published vectors, which is the only assurance that matters
 * here — a TOTP implementation that is subtly wrong still produces six plausible
 * digits and simply refuses everybody.
 *
 * SHA-1, six digits and thirty seconds are not chosen, they are what Google
 * Authenticator, 1Password, Aegis and the rest assume when a URI omits them. Changing
 * any of them makes enrolment fail in a way the person enrolling cannot diagnose.
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /**
     * How far either side of now a code is accepted.
     *
     * One step, because phone clocks drift and a code typed at second 29 arrives at
     * second 31. Every extra step multiplies both the window a stolen code survives
     * and the number of codes a guess can hit, so this does not grow to paper over
     * clock problems.
     */
    public const DRIFT = 1;

    private const ALGORITHM = 'sha1';

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh shared secret: 160 bits, the size RFC 4226 asks for. */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The time step a moment falls in. */
    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /** The code for one counter value — HOTP, which TOTP is with the clock as counter. */
    public static function code(string $secret, int $counter, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac(
            self::ALGORITHM,
            pack('J', $counter), // 64-bit, big-endian, as the RFC specifies.
            self::base32Decode($secret),
            true,
        );

        // Dynamic truncation: the low nibble of the last byte picks where to read.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code, and say which step it was.
     *
     * The step comes back rather than a bare true so the caller can refuse a step it
     * has already accepted. Verifying alone cannot stop replay: the same six digits
     * are correct for the whole window, so somebody who reads them over a shoulder
     * gets a minute and a half to use them unless something remembers.
     */
    public static function verify(string $secret, string $code, ?int $at = null, int $drift = self::DRIFT): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = self::step($at);
        $matched = null;

        for ($offset = -$drift; $offset <= $drift; $offset++) {
            // No early return: the loop runs the same number of times whichever step
            // matches, so the response time does not say how far out the clock is.
            if (hash_equals(self::code($secret, $now + $offset), $code)) {
                $matched = $now + $offset;
            }
        }

        return $matched;
    }

    /** The URI an authenticator app reads out of the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        // The issuer appears twice on purpose: in the label for apps that only read
        // that, and as a parameter for apps that read this. Both are encoded, because
        // an issuer is a product name and a workspace name is whatever somebody typed.
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /** Grouped in fours, which is how every app shows it and how people read it aloud. */
    public static function readable(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // Unpadded. Authenticator apps accept it, and a secret people retype by hand
        // is better off without a run of '=' on the end.
        return $encoded;
    }

    public static function base32Decode(string $secret): string
    {
        $bits = '';

        // Spaces and padding are stripped: the secret is shown grouped in fours and
        // somebody retyping it will copy the grouping too.
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '')) as $character) {
            $index = strpos(self::BASE32, $character);

            if ($index !== false) {
                $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            // A trailing partial byte is padding, not data.
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
