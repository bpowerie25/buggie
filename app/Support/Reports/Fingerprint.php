<?php

namespace App\Support\Reports;

/**
 * Groups reports that are the same bug.
 *
 * Forty people hitting one broken checkout should be one issue with a count of forty,
 * not forty tickets. The fingerprint is built from the three things that stay stable
 * across those forty reports and differ between genuinely different bugs:
 *
 *   normalised error message | first application stack frame | route pattern
 *
 * A report with no error attached gets no fingerprint. Human prose is not reliably
 * comparable, and wrongly merging two people's different problems is worse than
 * showing two inbox rows.
 */
class Fingerprint
{
    /** Frames from these paths are somebody else's code, not the bug's location. */
    private const VENDOR_MARKERS = [
        'node_modules', '/vendor/', 'chrome-extension://', 'moz-extension://',
        'webpack-internal:', '/buggy-widget', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com',
    ];

    /**
     * @param  array{message?: string|null, stack?: string|null}|null  $error
     */
    public static function for(?array $error, ?string $url): ?string
    {
        $message = trim((string) ($error['message'] ?? ''));

        if ($message === '') {
            return null;
        }

        return sha1(implode('|', [
            self::normalizeMessage($message),
            self::topApplicationFrame($error['stack'] ?? null) ?? '',
            self::routePattern($url) ?? '',
        ]));
    }

    /**
     * Strip the parts that differ between two reports of the same bug: ids, counts,
     * addresses, quoted values.
     */
    public static function normalizeMessage(string $message): string
    {
        $patterns = [
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => ':uuid',
            '/\bhttps?:\/\/\S+/i' => ':url',
            '/"[^"]*"|\'[^\']*\'/' => ':str',
            '/\b0x[0-9a-f]+\b/i' => ':hex',
            '/\b[0-9a-f]{8,}\b/i' => ':hex',
            '/\b\d+(\.\d+)?\b/' => ':n',
        ];

        $normalized = preg_replace(
            array_keys($patterns),
            array_values($patterns),
            $message,
        ) ?? $message;

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * The first stack frame that belongs to the application rather than a dependency —
     * where the bug actually is, rather than where it surfaced.
     */
    public static function topApplicationFrame(?string $stack): ?string
    {
        if ($stack === null || trim($stack) === '') {
            return null;
        }

        foreach (preg_split('/\r?\n/', $stack) ?: [] as $line) {
            $line = trim($line);

            // Excluding parentheses matters: frames are usually written
            // `at fn (https://host/file.js:12:44)` and a greedy match swallows the
            // bracket and stops at the line number.
            $pattern = '/((?:https?:\/\/)?[^\s()]+\.(?:js|jsx|ts|tsx|mjs)(?:\?[^\s():]*)?:\d+(?::\d+)?)/i';

            if ($line === '' || ! preg_match($pattern, $line, $m)) {
                continue;
            }

            $frame = $m[1];

            foreach (self::VENDOR_MARKERS as $marker) {
                if (str_contains($line, $marker)) {
                    continue 2;
                }
            }

            // Drop the origin and any cache-busting query, keep path:line:column.
            $frame = (string) preg_replace('#^https?://[^/]+#', '', $frame);
            $frame = (string) preg_replace('/\?[^:]*(?=:\d+:\d+$)/', '', $frame);

            // Build hashes change on every deploy; the path without one does not.
            return (string) preg_replace('/-[A-Za-z0-9_]{8,}(?=\.(js|ts|mjs|css))/', '', $frame);
        }

        return null;
    }

    /** /orders/1234/items/9 becomes /orders/:id/items/:id */
    public static function routePattern(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $segments = array_map(function (string $segment): string {
            if ($segment === '') {
                return $segment;
            }

            $isId = preg_match('/^\d+$/', $segment)
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $segment)
                || preg_match('/^[0-9a-f]{16,}$/i', $segment)
                // Mixed letters and digits with no vowels reads like a token, not a word.
                || (strlen($segment) > 12 && preg_match('/\d/', $segment) && preg_match('/[a-z]/i', $segment));

            return $isId ? ':id' : $segment;
        }, explode('/', $path));

        return rtrim(implode('/', $segments), '/') ?: '/';
    }
}
