<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody asked to be let in.
 *
 * Queued, so the form answers in the same time whether the request was kept and
 * mailed out or quietly dropped — the reply must not tell an address-prober which.
 *
 * Carries the requester's name and address and nothing else they wrote. Both reach an
 * admin's inbox from an unauthenticated form, so the name is stripped of anything the
 * mail template would turn into a link; the message waits on the screen, where it is
 * rendered as text.
 */
class AccessRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Plain values rather than the model: a tenant-scoped model restored inside a
     * queue worker, with no workspace bound, is a query waiting to go wrong.
     *
     * @param  string  $where  The workspace's name, or null for a central request.
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $where,
        public string $reviewUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = self::plain($this->name);
        $where = $this->where ?? 'a new workspace on this server';

        return (new MailMessage)
            ->subject("Somebody asked to join {$where}")
            ->line("{$name} ({$this->email}) has asked for access to {$where}.")
            ->action('Review the request', $this->reviewUrl)
            ->line('Nothing has been sent to them. Approving sends an ordinary invitation.');
    }

    /** Markdown-inert: no brackets, parentheses, angle brackets or emphasis. */
    public static function plain(string $text): string
    {
        return trim((string) preg_replace('/[\[\]()<>*_`#|!\\\\]/', '', $text));
    }
}
