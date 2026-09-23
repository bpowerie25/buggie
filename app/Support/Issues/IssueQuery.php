<?php

namespace App\Support\Issues;

use Stringable;

/**
 * The issue query language: one shareable string that is the whole filter state.
 *
 *   is:open assignee:@me -label:wontfix checkout
 *
 * Everything filterable is expressible here, the filter chips are just an editor for
 * this string, and a saved view is nothing more than a stored one. That is the reason
 * for a query language rather than a bag of query parameters: it keeps the URL, the
 * chips and saved views as a single representation instead of three that drift.
 *
 * Deliberately not Bugzilla's advanced search form, which is complete and unusable.
 */
final class IssueQuery implements Stringable
{
    /** Keys that accept a value, with the ones that may repeat. */
    public const KEYS = [
        'is', 'project', 'assignee', 'reporter', 'label', 'type', 'priority', 'version', 'no', 'field',
        'parent',
    ];

    /**
     * Keys that may appear more than once.
     *
     * `field` is multi because filtering on two custom fields at once is the obvious
     * thing to want — `field:environment=production field:browser=safari` — and a
     * single-valued key would silently keep only the last one.
     */
    private const MULTI = ['label', 'field'];

    /**
     * @param  array<string, array<int, string>>  $include
     * @param  array<string, array<int, string>>  $exclude
     */
    private function __construct(
        public readonly string $text,
        public readonly array $include,
        public readonly array $exclude,
    ) {}

    public static function parse(?string $input): self
    {
        $include = [];
        $exclude = [];
        $words = [];

        foreach (self::tokenize((string) $input) as $token) {
            $negated = str_starts_with($token, '-');
            $bare = $negated ? substr($token, 1) : $token;

            if (! str_contains($bare, ':')) {
                $words[] = $token;

                continue;
            }

            [$key, $value] = explode(':', $bare, 2);
            $key = strtolower(trim($key));
            $value = trim($value, " \t\"");

            if (! in_array($key, self::KEYS, true) || $value === '') {
                // Unknown operators are searched for literally rather than dropped,
                // so a stray colon never silently changes what you are looking at.
                $words[] = $token;

                continue;
            }

            $bucket = $negated ? 'exclude' : 'include';

            // Exclusions always accumulate: -assignee:a -assignee:b means neither.
            // Inclusions replace for single-valued keys, because an issue has one
            // assignee and the chip is a single choice.
            ${$bucket}[$key] = $negated || in_array($key, self::MULTI, true)
                ? array_values(array_unique([...(${$bucket}[$key] ?? []), $value]))
                : [$value];
        }

        return new self(implode(' ', $words), $include, $exclude);
    }

    /** Split on whitespace, keeping "quoted values" together. */
    private static function tokenize(string $input): array
    {
        preg_match_all('/-?[a-zA-Z]+:"[^"]*"|\S+/', trim($input), $matches);

        return array_filter($matches[0]);
    }

    /** open | closed | any — defaults to open, because that is what you meant. */
    public function state(): string
    {
        $value = strtolower($this->include['is'][0] ?? 'open');

        return in_array($value, ['open', 'closed', 'any'], true) ? $value : 'open';
    }

    public function first(string $key): ?string
    {
        return $this->include[$key][0] ?? null;
    }

    /** @return array<int, string> */
    public function all(string $key, bool $negated = false): array
    {
        return ($negated ? $this->exclude : $this->include)[$key] ?? [];
    }

    public function has(string $key, ?string $value = null, bool $negated = false): bool
    {
        $values = $this->all($key, $negated);

        return $value === null ? $values !== [] : in_array($value, $values, true);
    }

    /** Set a single-valued key, replacing whatever was there. Used by the chips. */
    public function with(string $key, string $value, bool $negated = false): self
    {
        $include = $this->include;
        $exclude = $this->exclude;
        $bucket = $negated ? 'exclude' : 'include';

        ${$bucket}[$key] = $negated || in_array($key, self::MULTI, true)
            ? array_values(array_unique([...(${$bucket}[$key] ?? []), $value]))
            : [$value];

        return new self($this->text, $include, $exclude);
    }

    public function without(string $key, ?string $value = null): self
    {
        $include = $this->include;
        $exclude = $this->exclude;

        foreach (['include', 'exclude'] as $bucket) {
            if (! isset(${$bucket}[$key])) {
                continue;
            }

            if ($value === null) {
                unset(${$bucket}[$key]);

                continue;
            }

            $remaining = array_values(array_diff(${$bucket}[$key], [$value]));
            $remaining === [] ? ${$bucket}[$key] = null : ${$bucket}[$key] = $remaining;

            if (${$bucket}[$key] === null) {
                unset(${$bucket}[$key]);
            }
        }

        return new self($this->text, $include, $exclude);
    }

    public function withText(string $text): self
    {
        return new self(trim($text), $this->include, $this->exclude);
    }

    /** Canonical form: operators first in a stable order, then free text. */
    public function __toString(): string
    {
        $parts = [];

        foreach (self::KEYS as $key) {
            foreach ($this->include[$key] ?? [] as $value) {
                $parts[] = $key.':'.self::quote($value);
            }

            foreach ($this->exclude[$key] ?? [] as $value) {
                $parts[] = '-'.$key.':'.self::quote($value);
            }
        }

        if ($this->text !== '') {
            $parts[] = $this->text;
        }

        return implode(' ', $parts);
    }

    private static function quote(string $value): string
    {
        return str_contains($value, ' ') ? '"'.$value.'"' : $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'query' => (string) $this,
            'text' => $this->text,
            'state' => $this->state(),
            'include' => $this->include,
            'exclude' => $this->exclude,
        ];
    }
}
