/**
 * Client-side mirror of App\Support\Issues\IssueQuery.
 *
 * The server sends the parsed structure, so this only has to rebuild the canonical
 * string when a chip changes something. Key order and quoting rules must match the
 * PHP so a query edited here compares equal to the same query saved as a view.
 */

export type QueryState = 'open' | 'closed' | 'any';

export interface ParsedQuery {
    query: string;
    text: string;
    state: QueryState;
    include: Record<string, string[]>;
    exclude: Record<string, string[]>;
}

// Must match IssueQuery::KEYS — it determines the canonical ordering.
const KEYS = ['is', 'project', 'assignee', 'reporter', 'label', 'type', 'priority', 'no'];
const MULTI = ['label'];

function quote(value: string) {
    return value.includes(' ') ? `"${value}"` : value;
}

export function stringify(parsed: ParsedQuery): string {
    const parts: string[] = [];

    for (const key of KEYS) {
        for (const value of parsed.include[key] ?? []) parts.push(`${key}:${quote(value)}`);
        for (const value of parsed.exclude[key] ?? []) parts.push(`-${key}:${quote(value)}`);
    }

    if (parsed.text) parts.push(parsed.text);

    return parts.join(' ');
}

function clone(parsed: ParsedQuery): ParsedQuery {
    return {
        ...parsed,
        include: Object.fromEntries(
            Object.entries(parsed.include).map(([k, v]) => [k, [...v]]),
        ),
        exclude: Object.fromEntries(
            Object.entries(parsed.exclude).map(([k, v]) => [k, [...v]]),
        ),
    };
}

export function withTerm(
    parsed: ParsedQuery,
    key: string,
    value: string,
    negated = false,
): string {
    const next = clone(parsed);
    const bucket = negated ? next.exclude : next.include;

    // Exclusions accumulate; inclusions replace for single-valued keys. Mirrors
    // IssueQuery::with() — see the comment there.
    bucket[key] =
        negated || MULTI.includes(key)
            ? [...new Set([...(bucket[key] ?? []), value])]
            : [value];

    return stringify(next);
}

/** Omit `value` to clear the key entirely, in both polarities. */
export function withoutTerm(parsed: ParsedQuery, key: string, value?: string): string {
    const next = clone(parsed);

    for (const bucket of [next.include, next.exclude]) {
        if (!bucket[key]) continue;

        if (value === undefined) {
            delete bucket[key];
            continue;
        }

        bucket[key] = bucket[key].filter((v) => v !== value);
        if (bucket[key].length === 0) delete bucket[key];
    }

    return stringify(next);
}

export function withText(parsed: ParsedQuery, text: string): string {
    return stringify({ ...clone(parsed), text: text.trim() });
}

/** How many operators are active — drives the "clear filters" affordance. */
export function termCount(parsed: ParsedQuery): number {
    const count = (bag: Record<string, string[]>) =>
        Object.entries(bag).reduce(
            (total, [key, values]) => total + (key === 'is' ? 0 : values.length),
            0,
        );

    return count(parsed.include) + count(parsed.exclude);
}
