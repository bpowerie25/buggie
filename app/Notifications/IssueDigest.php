<?php

namespace App\Notifications;

use App\Enums\NotificationReason;
use App\Models\Issue;
use App\Support\Notifications\DueReminderSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Everything that happened to one issue, for one person, since the last send.
 */
class IssueDigest extends Notification
{
    use Queueable;

    /** @param Collection<int, \App\Models\PendingNotification> $entries */
    public function __construct(
        public Issue $issue,
        public Collection $entries,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = $this->lines();

        $mail = (new MailMessage)
            ->subject("[{$this->issue->key}] {$this->issue->title}")
            // Replies come back to this comment thread rather than to a no-reply void.
            ->replyTo($this->replyAddress())
            ->greeting("{$this->issue->key}: {$this->issue->title}");

        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail
            ->action('Open issue', workspace_url($this->issue->workspace->slug, 'issues/'.$this->issue->key))
            ->line('Reply to this email to comment on the issue.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'issue_key' => $this->issue->key,
            'issue_title' => $this->issue->title,
            'reasons' => $this->entries->pluck('reason')->unique()->values()->all(),
            'lines' => $this->lines(),
        ];
    }

    /** @return array<int, string> */
    private function lines(): array
    {
        return $this->entries
            ->map(function ($entry): string {
                $actor = $entry->actor?->name ?? 'Someone';
                $reason = NotificationReason::from($entry->reason);

                return match ($reason) {
                    NotificationReason::Assigned => "{$actor} assigned this to you.",
                    NotificationReason::Mentioned => "{$actor} mentioned you.",
                    NotificationReason::Commented => "{$actor} commented: "
                        .\Illuminate\Support\Str::limit($entry->data['excerpt'] ?? '', 140),
                    NotificationReason::StatusChanged => "{$actor} moved this to "
                        .($entry->data['to'] ?? 'a new status').'.',
                    NotificationReason::Reported => "{$actor} updated an issue you reported.",
                    // Nobody did this one: the date arrived. Naming an actor here
                    // would put somebody's name on a deadline they did not set.
                    NotificationReason::DueDate => DueReminderSchedule::sentence(
                        (int) ($entry->data['days'] ?? 0),
                    ),
                };
            })
            ->unique()
            ->values()
            ->all();
    }

    private function replyAddress(): string
    {
        return 'reply+'.$this->issue->key.'.'.$this->issue->project->inbound_token
            .'@'.config('buggie.inbound_domain');
    }
}
