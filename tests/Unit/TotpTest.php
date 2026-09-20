<?php

namespace Tests\Unit;

use App\Support\TwoFactor\RecoveryCodes;
use App\Support\TwoFactor\Totp;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The algorithm, on its own.
 *
 * A TOTP implementation that is subtly wrong does not look wrong: it produces six
 * plausible digits and refuses everybody, and the fault reads as "my phone's clock
 * must be off". The RFC's own vectors are the only thing that settles it.
 */
class TotpTest extends TestCase
{
    /** RFC 6238, Appendix B. The seed is ASCII "12345678901234567890", SHA-1, 8 digits. */
    private const SEED = '12345678901234567890';

    #[Test]
    public function it_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode(self::SEED);

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame(
                $expected,
                Totp::code($secret, Totp::step($time), digits: 8),
                "RFC 6238 vector at t={$time}",
            );
        }
    }

    #[Test]
    public function base32_survives_a_round_trip(): void
    {
        // The vectors above only prove the decoder against one seed. Random bytes
        // exercise the partial-byte cases at the end of the string.
        foreach ([1, 5, 10, 16, 20, 32] as $length) {
            $bytes = random_bytes($length);

            $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }
    }

    #[Test]
    public function the_grouping_shown_to_people_still_decodes(): void
    {
        // The secret is printed in blocks of four so it can be retyped, and somebody
        // retyping it will copy the spaces in.
        $secret = Totp::secret();

        $this->assertSame(
            Totp::base32Decode($secret),
            Totp::base32Decode(Totp::readable($secret)),
        );
    }

    #[Test]
    public function one_step_of_drift_is_allowed_and_two_is_not(): void
    {
        $secret = Totp::secret();
        $now = time();

        // The positive control: without this, "two steps out is refused" would also
        // pass on an implementation that refuses everything.
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, Totp::step($now)), $now));
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, Totp::step($now) - 1), $now));
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, Totp::step($now) + 1), $now));

        $this->assertNull(Totp::verify($secret, Totp::code($secret, Totp::step($now) - 2), $now));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, Totp::step($now) + 2), $now));
    }

    #[Test]
    public function verify_reports_which_step_matched(): void
    {
        // The caller needs this to refuse a step it has already accepted; a bare
        // true would make replay undetectable.
        $secret = Totp::secret();
        $now = time();
        $step = Totp::step($now);

        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $now));
    }

    #[Test]
    public function a_secret_is_a_hundred_and_sixty_bits(): void
    {
        // RFC 4226 §4 R6. Two secrets in a row being equal would also mean the
        // source of randomness is not one.
        $this->assertSame(20, strlen(Totp::base32Decode(Totp::secret())));
        $this->assertNotSame(Totp::secret(), Totp::secret());
    }

    #[Test]
    public function the_uri_says_what_an_authenticator_app_needs(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'someone@example.com', 'Buggie');

        $this->assertStringStartsWith('otpauth://totp/Buggie:someone%40example.com?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Buggie', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    #[Test]
    public function rubbish_in_the_box_is_refused_rather_than_coerced(): void
    {
        $secret = Totp::secret();
        $now = time();
        $valid = Totp::code($secret, Totp::step($now));

        $this->assertNotNull(Totp::verify($secret, $valid, $now));

        foreach (['', '123', '12345678', 'abcdef', $valid.'0'] as $rubbish) {
            $this->assertNull(Totp::verify($secret, $rubbish, $now), "accepted [{$rubbish}]");
        }

        // Spaces are how a phone displays it — "123 456" is the same code.
        $this->assertNotNull(
            Totp::verify($secret, substr($valid, 0, 3).' '.substr($valid, 3), $now),
        );
    }

    #[Test]
    public function recovery_codes_are_distinct_and_unambiguous(): void
    {
        $codes = RecoveryCodes::generate();

        $this->assertCount(RecoveryCodes::COUNT, $codes);
        $this->assertCount(RecoveryCodes::COUNT, array_unique($codes));

        foreach ($codes as $code) {
            // No I, O, 0 or 1: these are read off a printout by somebody who has just
            // lost their phone.
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/', $code);
        }
    }

    #[Test]
    public function a_recovery_code_matches_however_it_was_typed(): void
    {
        $codes = RecoveryCodes::generate();
        $hashes = RecoveryCodes::hashAll($codes);

        $this->assertSame(0, RecoveryCodes::match($codes[0], $hashes));
        $this->assertSame(0, RecoveryCodes::match(strtolower($codes[0]), $hashes));
        $this->assertSame(0, RecoveryCodes::match(str_replace('-', '', $codes[0]), $hashes));
        $this->assertSame(3, RecoveryCodes::match($codes[3], $hashes));

        // The control for the above: something that is not one of them does not match.
        $this->assertNull(RecoveryCodes::match('AAAAA-AAAAA', $hashes));
    }

    #[Test]
    public function stored_recovery_codes_cannot_be_read_back(): void
    {
        $codes = RecoveryCodes::generate();

        foreach (RecoveryCodes::hashAll($codes) as $index => $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
            $this->assertStringNotContainsString(
                RecoveryCodes::normalise($codes[$index]),
                $hash,
            );
        }
    }
}
