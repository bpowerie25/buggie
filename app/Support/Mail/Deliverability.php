<?php

namespace App\Support\Mail;

/**
 * Whether mail sent from this install would actually reach anybody.
 *
 * Broken mail is the quietest failure in the product. Invitations, password resets
 * and every notification are queued, accepted and discarded, with nothing on screen,
 * nothing in a flash message and nothing a user could think to report. An install
 * left on the `log` driver looks identical to one that works until somebody says
 * they never got their invitation — and they have no way to say so, because the way
 * in was the invitation.
 *
 * So this is deliberately about the *configuration*, not about a send succeeding. A
 * send that throws is already reported where it happens; this catches the case where
 * nothing throws at all.
 */
class Deliverability
{
    /** Drivers that accept a message and then throw it away. */
    private const DISCARDS = ['log', 'array', 'null'];

    public function isConfigured(): bool
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, self::DISCARDS, true)) {
            return false;
        }

        // A host of "" is the state a half-finished form leaves behind, and SMTP
        // against no host fails on every send rather than at the point it was set.
        if ($mailer === 'smtp') {
            return trim((string) config('mail.mailers.smtp.host')) !== '';
        }

        // Anything else — ses, postmark, resend, sendmail — is assumed to be a
        // deliberate choice by somebody who meant it.
        return true;
    }

    /**
     * Why it will not be delivered, in words an operator can act on, or null when it
     * will be.
     */
    public function reason(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        return in_array((string) config('mail.default'), self::DISCARDS, true)
            ? 'This install is not set up to send mail, so invitations, password resets and notifications are written to the log and discarded.'
            : 'Mail is set to use SMTP but no server has been given, so nothing can be sent.';
    }
}
