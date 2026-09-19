<?php

namespace App\Support\Webhooks;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Issue;
use App\Models\Report;
use App\Models\Webhook;

/**
 * Firing webhooks, from the few places worth firing them.
 *
 * Called explicitly from the actions rather than observed off model events: a
 * webhook is a promise to somebody outside, and it should be obvious from reading
 * the code that changing an issue tells the world.
 */
class Webhooks
{
    public static function issue(WebhookEvent $event, Issue $issue): void
    {
        self::fire($event, $issue->project_id, [
            'key' => $issue->key,
            'title' => $issue->title,
            'type' => $issue->type->value,
            'priority' => $issue->priority->value,
            'status' => $issue->status?->name,
            'open' => $issue->status?->category->isOpen(),
            'project' => $issue->project?->only(['key', 'name', 'slug']),
            'assignee' => $issue->assignee?->only(['name']),
            'url' => self::url("issues/{$issue->key}"),
        ]);
    }

    public static function comment(Issue $issue, string $author, bool $internal): void
    {
        // Internal notes are not sent. A webhook is an outside audience, and the
        // whole point of an internal note is that it has none.
        if ($internal) {
            return;
        }

        self::fire(WebhookEvent::CommentCreated, $issue->project_id, [
            'issue' => $issue->key,
            'title' => $issue->title,
            'author' => $author,
            'url' => self::url("issues/{$issue->key}"),
        ]);
    }

    public static function report(Report $report): void
    {
        self::fire(WebhookEvent::ReportReceived, $report->project_id, [
            'id' => $report->id,
            'title' => $report->title,
            'project' => $report->project?->only(['key', 'name', 'slug']),
            'url' => self::url('inbox'),
        ]);
    }

    /**
     * A link back, built from the resolved tenant rather than the model's relation.
     *
     * Reading $issue->workspace here is a lazy load, which strict mode turns into an
     * exception — and a bulk update hands us issues without it. The tenant is already
     * known; asking the database again would be a query to learn something we have.
     */
    private static function url(string $path): string
    {
        return workspace_url(app(\App\Support\Tenancy\Tenancy::class)->currentOrFail()->slug, $path);
    }

    /** @param array<string, mixed> $payload */
    private static function fire(WebhookEvent $event, ?int $projectId, array $payload): void
    {
        foreach (Webhook::where('is_active', true)->get() as $webhook) {
            if (! $webhook->wants($event, $projectId)) {
                continue;
            }

            DeliverWebhook::dispatch(
                $webhook->id,
                $webhook->workspace_id,
                $event->value,
                $payload,
            );
        }
    }
}
