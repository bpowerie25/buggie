<?php

namespace App\Support\Chat;

/**
 * One thing worth saying in a channel, before either service has had its say.
 *
 * Deliberately not the webhook payload. That payload is a published contract —
 * `docs/help/webhooks.md` documents its keys and customers parse it — so shaping a
 * Slack message out of it would mean a wording change here becoming a breaking
 * change there. This carries what a human reads: a heading, a headline, a handful of
 * labelled facts and a link.
 *
 * What it deliberately does not carry: issue descriptions and comment text. See
 * `docs/help/chat-notifications.md` for why.
 */
class ChatNotice
{
    /** @param array<string, string|null> $fields Label => value, in display order. */
    public function __construct(
        public string $heading,
        public string $title,
        public ?string $url = null,
        public array $fields = [],
        public string $accent = '6366F1',
    ) {}

    /**
     * The facts worth printing: anything unset is dropped rather than shown empty.
     *
     * An issue with no assignee should not say "Assignee: —"; a row that says
     * nothing is worse than no row, because it takes up a line in a channel.
     *
     * @return array<string, string>
     */
    public function facts(): array
    {
        return array_filter(
            $this->fields,
            fn (?string $value) => $value !== null && $value !== '',
        );
    }

    /** One line of text for a notification preview, where no card is rendered. */
    public function summary(): string
    {
        return "{$this->heading}: {$this->title}";
    }
}
