<?php

namespace App\Support\Chat;

/**
 * Block Kit, which is what a Slack incoming webhook renders.
 *
 * `text` is still sent alongside the blocks. Slack uses it for the notification
 * preview on a phone and in the channel list, and a message with blocks but no text
 * arrives there as a blank line.
 */
class SlackMessage implements ChatMessage
{
    /** Slack renders at most ten fields in a section and silently drops the rest. */
    private const MAX_FIELDS = 10;

    /** @return array<string, mixed> */
    public function body(ChatNotice $notice, string $endpoint): array
    {
        $headline = $notice->url === null
            ? '*'.self::escape($notice->title).'*'
            : '*<'.self::escape($notice->url).'|'.self::escape($notice->title).'>*';

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $headline."\n".self::escape($notice->heading),
                ],
            ],
        ];

        $facts = array_slice($notice->facts(), 0, self::MAX_FIELDS, true);

        if ($facts !== []) {
            $blocks[] = [
                'type' => 'section',
                'fields' => array_values(array_map(
                    fn (string $value, string $label) => [
                        'type' => 'mrkdwn',
                        'text' => '*'.self::escape($label)."*\n".self::escape($value),
                    ],
                    $facts,
                    array_keys($facts),
                )),
            ];
        }

        return [
            'text' => self::escape($notice->summary()),
            'blocks' => $blocks,
        ];
    }

    /**
     * Slack's three reserved characters, and only those three.
     *
     * Without this an issue titled `<img src=x>` or `a > b` breaks the link syntax
     * around it and the message arrives mangled or without its link. Slack asks for
     * exactly these three and asks that nothing else be escaped, so a general-purpose
     * HTML escaper is the wrong tool — it would turn every apostrophe into `&#039;`
     * in the channel.
     */
    private static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }
}
