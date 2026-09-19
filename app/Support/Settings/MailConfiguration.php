<?php

namespace App\Support\Settings;

/**
 * Lets the database override the mail configuration.
 *
 * So that changing an SMTP password is a form rather than an edit to .env and a
 * redeploy — the kind of friction that makes people leave mail broken. And broken
 * mail is silent: invitations, password resets and every notification simply never
 * arrive, with nothing on screen to say so.
 *
 * A class rather than a method on the service provider because it is worth testing,
 * and a private method inside a provider can only be tested by booting a whole
 * application, which is more machinery than the thing being tested.
 */
class MailConfiguration
{
    public function __construct(private Settings $settings) {}

    public function apply(): void
    {
        $mailer = $this->settings->get('mail.mailer');

        // Nothing stored: the environment keeps its say, so an install that would
        // rather configure mail the traditional way is not forced to stop.
        if ($mailer === null) {
            return;
        }

        config([
            'mail.default' => $mailer,
            'mail.mailers.smtp.host' => $this->settings->get('mail.host', config('mail.mailers.smtp.host')),
            'mail.mailers.smtp.port' => (int) $this->settings->get('mail.port', config('mail.mailers.smtp.port')),
            'mail.mailers.smtp.username' => $this->settings->get('mail.username', config('mail.mailers.smtp.username')),
            'mail.mailers.smtp.password' => $this->settings->get('mail.password', config('mail.mailers.smtp.password')),
            'mail.mailers.smtp.encryption' => $this->settings->get('mail.encryption', config('mail.mailers.smtp.encryption')),
            'mail.from.address' => $this->settings->get('mail.from_address', config('mail.from.address')),
            'mail.from.name' => $this->settings->get('mail.from_name', config('mail.from.name')),
        ]);
    }
}
