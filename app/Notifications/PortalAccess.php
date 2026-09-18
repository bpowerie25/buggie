<?php

namespace App\Notifications;

use App\Models\PortalToken;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever reported a bug, so they can follow it without being made to create
 * an account.
 */
class PortalAccess extends Notification
{
    use Queueable;

    public function __construct(public PortalToken $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $issue = $this->token->issue;

        return (new MailMessage)
            ->subject("We're looking into your report: {$issue->title}")
            ->greeting('Thanks for the report')
            ->line("We've logged it as {$issue->key} and you can follow it at the link below.")
            ->action('Follow your report', $this->token->url())
            ->line('You can reply there, and you will hear from us when it changes.')
            ->line('The link is private to you — please do not forward it.');
    }
}
