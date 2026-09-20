<?php

namespace App\Support\Chat;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverChatMessage;
use App\Models\ChatIntegration;
use App\Models\Issue;
use App\Models\Report;
use App\Support\Tenancy\Tenancy;

/**
 * Announcing things in Slack and Teams, from the few places worth announcing them.
 *
 * Called explicitly from the actions, beside the webhook calls and for the same
 * reason: telling the world should be visible in the code that changes the issue,
 * not hidden in a model observer.
 *
 * The event vocabulary is WebhookEvent's. There is no second list of events to keep
 * in step, and "send this to a webhook" and "send this to Slack" mean the same thing
 * to whoever is subscribing.
 *
 * What is deliberately absent from every message: issue descriptions and comment
 * text. Buggie cannot see who is in a channel it was handed a URL for, so the safe
 * assumption is that somebody is reading who should not be reading the body of an
 * internal note. See `docs/help/chat-notifications.md`.
 */
class ChatNotifications
{
    public static function issue(WebhookEvent $event, Issue $issue): void
    {
        /*
         * An internal issue is internal in a chat channel too.
         *
         * This went out as `false` — every subscribed channel heard every issue,
         * whatever its visibility. That is fine for an agency's own Slack and wrong
         * the moment somebody points an integration at a client's, which is a
         * reasonable thing to want to do and the per-project filter invites. A title
         * is short, but "Refund flow still broken for Acme" is exactly the sentence
         * you would not want that client to read.
         *
         * So it follows the rule the rest of the product follows: internal unless
         * somebody decided otherwise. A channel ticked as the team's own hears
         * everything; any other channel hears only what a client could already see.
         */
        $internal = $issue->visibility !== \App\Enums\IssueVisibility::Client;

        self::fire($event, $issue->project_id, $internal, new ChatNotice(
            heading: self::heading($event),
            title: self::titleOf($issue),
            url: self::url("issues/{$issue->key}"),
            fields: [
                'Project' => $issue->project?->name,
                'Status' => $issue->status?->name,
                'Priority' => $issue->priority?->label(),
                'Assignee' => $issue->assignee?->name,
            ],
            accent: self::accent($event),
        ));
    }

    public static function comment(Issue $issue, string $author, bool $internal): void
    {
        // No fields here, and no relations touched: a comment can be added to an
        // issue that was loaded without its project, and strict mode turns that
        // lazy load into an exception on the request that added the comment.
        self::fire(WebhookEvent::CommentCreated, $issue->project_id, $internal, new ChatNotice(
            heading: $internal
                ? "Internal note from {$author}"
                : "New comment from {$author}",
            title: self::titleOf($issue),
            url: self::url("issues/{$issue->key}"),
            accent: self::accent(WebhookEvent::CommentCreated),
        ));
    }

    public static function report(Report $report): void
    {
        // Triage is staff work and the inbox is staff-only — the unread badge is
        // hidden from clients so as not to tell them an inbox exists at all. A
        // channel that is not the team's own has no business hearing about one.
        self::fire(WebhookEvent::ReportReceived, $report->project_id, true, new ChatNotice(
            heading: self::heading(WebhookEvent::ReportReceived),
            title: (string) $report->title,
            // The inbox rather than the report: a report is triaged from the list,
            // and it may well have been grouped into another one by the time
            // somebody clicks.
            url: self::url('inbox'),
            fields: ['Project' => $report->project?->name],
            accent: self::accent(WebhookEvent::ReportReceived),
        ));
    }

    private static function titleOf(Issue $issue): string
    {
        return trim("{$issue->key} · {$issue->title}");
    }

    private static function heading(WebhookEvent $event): string
    {
        return match ($event) {
            WebhookEvent::IssueCreated => 'New issue',
            WebhookEvent::IssueUpdated => 'Issue updated',
            WebhookEvent::IssueClosed => 'Issue closed',
            WebhookEvent::CommentCreated => 'New comment',
            WebhookEvent::ReportReceived => 'New report from the widget',
        };
    }

    /** Teams paints the card's left edge with this; Slack ignores it. */
    private static function accent(WebhookEvent $event): string
    {
        return match ($event) {
            WebhookEvent::IssueClosed => '16A34A',
            WebhookEvent::ReportReceived => 'F59E0B',
            default => '6366F1',
        };
    }

    /**
     * A link back, built from the resolved tenant rather than the model's relation.
     *
     * Same reason as Webhooks::url(): reading $issue->workspace is a lazy load that
     * strict mode turns into an exception, and a bulk update hands us issues without
     * it. The tenant is already known.
     */
    private static function url(string $path): string
    {
        return workspace_url(app(Tenancy::class)->currentOrFail()->slug, $path);
    }

    private static function fire(WebhookEvent $event, ?int $projectId, bool $internal, ChatNotice $notice): void
    {
        foreach (ChatIntegration::where('is_active', true)->get() as $integration) {
            if (! $integration->wants($event, $projectId, $internal)) {
                continue;
            }

            DeliverChatMessage::dispatch(
                $integration->id,
                $integration->workspace_id,
                $event->value,
                $notice,
            );
        }
    }
}
