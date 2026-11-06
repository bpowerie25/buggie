<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A report arrived that nothing could group, and is waiting for a person.
 *
 * Plain values, not the model, for the reason AccessRequested gives. The title comes
 * from an unauthenticated form, so it is made markdown-inert and bounded before it
 * reaches an inbox; the body and the reporter's address stay on the Triage screen.
 */
class ReportNeedsTriage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $project,
        public string $inboxUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = \Illuminate\Support\Str::limit(AccessRequested::plain($this->title), 120);
        $project = AccessRequested::plain($this->project);

        return (new MailMessage)
            ->subject("New report in {$project}: {$title}")
            ->line("A new report is waiting in Triage for {$project}.")
            ->line("“{$title}”")
            ->action('Open Triage', $this->inboxUrl)
            ->line('You are getting this because you are on the triage list for this project.');
    }
}
