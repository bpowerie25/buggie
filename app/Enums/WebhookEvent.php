<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case IssueCreated = 'issue.created';
    case IssueUpdated = 'issue.updated';
    case IssueClosed = 'issue.closed';
    case CommentCreated = 'comment.created';
    case ReportReceived = 'report.received';

    public function label(): string
    {
        return match ($this) {
            self::IssueCreated => 'An issue is created',
            self::IssueUpdated => 'An issue changes',
            self::IssueClosed => 'An issue is closed',
            self::CommentCreated => 'Somebody comments',
            self::ReportReceived => 'A report arrives from the widget',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $event) => ['value' => $event->value, 'label' => $event->label()],
            self::cases(),
        );
    }
}
