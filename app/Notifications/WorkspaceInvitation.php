<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitation extends Notification
{
    use Queueable;

    public function __construct(public Invitation $invitation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace;
        $inviter = $this->invitation->invitedBy?->name ?? 'Someone';

        return (new MailMessage)
            ->subject("{$inviter} invited you to {$workspace->name} on Buggy")
            ->greeting('You have been invited')
            ->line("{$inviter} has invited you to join {$workspace->name} as a "
                .strtolower($this->invitation->role->label()).'.')
            ->action('Accept invitation', $this->invitation->url())
            ->line('This invitation expires in '.Invitation::LIFETIME_DAYS.' days.');
    }
}
