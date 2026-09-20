<?php

namespace App\Enums;

use App\Support\Chat\ChatMessage;
use App\Support\Chat\SlackMessage;
use App\Support\Chat\TeamsMessage;

/**
 * The two chat services Buggie can post into.
 *
 * Both are incoming-webhook style: somebody pastes a URL and we POST to it. Neither
 * is an OAuth application, which would need a registered app and a review process.
 */
enum ChatProvider: string
{
    case Slack = 'slack';
    case Teams = 'teams';

    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
        };
    }

    /** What to show under the URL field, because the two are found in different places. */
    public function hint(): string
    {
        return match ($this) {
            self::Slack => 'Slack → your app → Incoming Webhooks → Add New Webhook to Workspace.',
            self::Teams => 'Teams → the channel → Workflows → "Post to a channel when a webhook request is received".',
        };
    }

    /**
     * Slack speaks Block Kit and Teams speaks cards, and the two disagree about
     * almost everything. Kept in separate classes so neither's quirks leak into the
     * other; this is the only place that knows both exist.
     */
    public function formatter(): ChatMessage
    {
        return match ($this) {
            self::Slack => new SlackMessage,
            self::Teams => new TeamsMessage,
        };
    }

    /** @return array<int, array{value: string, label: string, hint: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'hint' => $provider->hint(),
            ],
            self::cases(),
        );
    }
}
