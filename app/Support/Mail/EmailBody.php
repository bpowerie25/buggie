<?php

namespace App\Support\Mail;

/**
 * Extracts what someone actually wrote from a reply.
 *
 * An email reply is mostly not the reply: it is the reply, then the quoted thread,
 * then a signature, then a legal footer. Storing all of that as a comment makes the
 * thread unreadable within about three messages.
 */
class EmailBody
{
    /** Lines that introduce quoted history, in the forms the common clients use. */
    private const QUOTE_MARKERS = [
        '/^On .+ wrote:\s*$/i',
        '/^-{2,}\s*Original Message\s*-{2,}/i',
        '/^_{5,}\s*$/',
        '/^From:\s.+$/i',
        '/^Sent from my \w+/i',
        '/^Le .+ a écrit\s*:\s*$/iu',
        '/^Am .+ schrieb .+:\s*$/iu',
    ];

    public static function extract(?string $body): string
    {
        if ($body === null || trim($body) === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // "-- " on its own line is the standard signature delimiter.
            if ($trimmed === '--' || $trimmed === '--') {
                break;
            }

            if (self::isQuoteMarker($trimmed)) {
                break;
            }

            // Drop quoted lines wherever they appear, in case the marker was missed.
            if (str_starts_with($trimmed, '>')) {
                continue;
            }

            $kept[] = rtrim($line);
        }

        $text = trim(implode("\n", $kept));

        // Collapse the run of blank lines left behind by removed quotes.
        return (string) preg_replace("/\n{3,}/", "\n\n", $text);
    }

    private static function isQuoteMarker(string $line): bool
    {
        foreach (self::QUOTE_MARKERS as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull the routing token out of a recipient address.
     *
     * bugs+abc123@in.buggie.eu        -> ['bugs', 'abc123']
     * reply+WEB-12.abc123@in.buggie.eu -> ['reply', 'WEB-12.abc123']
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseRecipient(?string $recipient): ?array
    {
        if ($recipient === null) {
            return null;
        }

        // The header may be "Name <addr>" or a comma-separated list.
        foreach (preg_split('/\s*,\s*/', $recipient) ?: [] as $candidate) {
            if (preg_match('/<([^>]+)>/', $candidate, $m)) {
                $candidate = $m[1];
            }

            $local = strtolower(trim(explode('@', trim($candidate))[0] ?? ''));

            if (preg_match('/^(bugs|reply)\+(.+)$/', $local, $m)) {
                return [$m[1], $m[2]];
            }
        }

        return null;
    }

    /** "Ana Silva <ana@example.com>" -> "Ana Silva" */
    public static function senderName(?string $from): ?string
    {
        if ($from === null) {
            return null;
        }

        if (preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>\s*$/', $from, $m)) {
            return trim($m[1]) ?: null;
        }

        return null;
    }

    public static function senderEmail(?string $from): ?string
    {
        if ($from === null) {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }

        return filter_var(trim($from), FILTER_VALIDATE_EMAIL) ? strtolower(trim($from)) : null;
    }
}
