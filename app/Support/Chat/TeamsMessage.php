<?php

namespace App\Support\Chat;

/**
 * Teams, which accepts two different formats depending on where the URL came from.
 *
 * Microsoft is retiring the Office 365 connector — the thing that produced a
 * `*.webhook.office.com` address and renders a MessageCard — and the replacement is
 * a Power Automate workflow, whose HTTP trigger has some other host entirely and
 * expects an Adaptive Card inside a message envelope. Neither understands the
 * other's JSON: send a MessageCard to a workflow and it posts nothing at all.
 *
 * So the format is chosen from the host. Sniffing a URL to decide what to send is
 * not pretty, but the alternative is asking the customer which kind of Teams URL
 * they pasted, and they do not know.
 */
class TeamsMessage implements ChatMessage
{
    /** The hosts an Office 365 connector hands out. Everything else is a workflow. */
    private const CONNECTOR_HOSTS = [
        'webhook.office.com',
        'outlook.office.com',
        'outlook.office365.com',
    ];

    /** @return array<string, mixed> */
    public function body(ChatNotice $notice, string $endpoint): array
    {
        return self::isConnector($endpoint)
            ? $this->messageCard($notice)
            : $this->adaptiveCard($notice);
    }

    /** @return array<string, mixed> */
    private function messageCard(ChatNotice $notice): array
    {
        $card = [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            // Shown in the notification toast and the activity feed. A card with no
            // summary is rejected outright rather than rendered plainly.
            'summary' => $notice->summary(),
            'themeColor' => $notice->accent,
            'title' => $notice->title,
            'sections' => [array_filter([
                'activitySubtitle' => $notice->heading,
                'facts' => self::facts($notice, 'name', 'value'),
                // Off, so an issue titled `**urgent**` or `[click](http://…)` is
                // shown as somebody typed it rather than rendered as markup.
                'markdown' => false,
            ], fn ($value) => $value !== [])],
        ];

        if ($notice->url !== null) {
            $card['potentialAction'] = [[
                '@type' => 'OpenUri',
                'name' => 'Open in Buggie',
                'targets' => [['os' => 'default', 'uri' => $notice->url]],
            ]];
        }

        return $card;
    }

    /** @return array<string, mixed> */
    private function adaptiveCard(ChatNotice $notice): array
    {
        $body = [
            [
                'type' => 'TextBlock',
                'text' => $notice->heading,
                'wrap' => true,
                'isSubtle' => true,
                'spacing' => 'None',
            ],
            [
                'type' => 'TextBlock',
                'text' => $notice->title,
                'wrap' => true,
                'weight' => 'Bolder',
                'size' => 'Medium',
            ],
        ];

        if ($facts = self::facts($notice, 'title', 'value')) {
            $body[] = ['type' => 'FactSet', 'facts' => $facts];
        }

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            // 1.4 rather than the newest: a workflow posts into whatever client the
            // reader has, and an unsupported version renders as nothing.
            'version' => '1.4',
            'body' => $body,
        ];

        if ($notice->url !== null) {
            $card['actions'] = [[
                'type' => 'Action.OpenUrl',
                'title' => 'Open in Buggie',
                'url' => $notice->url,
            ]];
        }

        // The envelope a workflow's "post a card" step expects. The card on its own
        // is accepted with a 202 and then never appears in the channel.
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => $card,
            ]],
        ];
    }

    /** @return array<int, array<string, string>> */
    private static function facts(ChatNotice $notice, string $labelKey, string $valueKey): array
    {
        return array_values(array_map(
            fn (string $value, string $label) => [$labelKey => $label, $valueKey => $value],
            $notice->facts(),
            array_keys($notice->facts()),
        ));
    }

    private static function isConnector(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        foreach (self::CONNECTOR_HOSTS as $connector) {
            if ($host === $connector || str_ends_with($host, '.'.$connector)) {
                return true;
            }
        }

        return false;
    }
}
