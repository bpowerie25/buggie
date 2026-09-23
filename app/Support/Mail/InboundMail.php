<?php

namespace App\Support\Mail;

/**
 * Whether email can actually be filed into a project.
 *
 * Two things have to be true, and neither is true by default. The project settings
 * screen offered a copyable address regardless — `bugs+token@in.buggie.test`, the
 * placeholder domain — so somebody would copy it, email it, and get silence. The same
 * shape of problem as an install sitting on the `log` mailer: it looks configured
 * because there is something on screen.
 */
class InboundMail
{
    /** The domain shipped as a default, which is a stand-in rather than a destination. */
    private const PLACEHOLDER = 'in.buggie.test';

    public function isConfigured(): bool
    {
        return $this->reason() === null;
    }

    /** Why mail cannot be filed in, in words an operator can act on, or null when it can. */
    public function reason(): ?string
    {
        $domain = trim((string) config('buggie.inbound_domain'));

        if ($domain === '' || $domain === self::PLACEHOLDER) {
            return 'No inbound domain is set, so the address below is a placeholder. '
                .'Set MAIL_INBOUND_DOMAIN to a domain whose mail reaches this install.';
        }

        /*
         * Without a signing key the webhook refuses everything, which is correct — it
         * would otherwise accept an issue from anybody who found the URL — but it
         * means a correctly addressed email still vanishes.
         */
        if (! config('buggie.mailgun_signing_key')) {
            return 'No Mailgun signing key is set, so incoming mail cannot be verified '
                .'and is refused. Set MAILGUN_SIGNING_KEY.';
        }

        return null;
    }
}
