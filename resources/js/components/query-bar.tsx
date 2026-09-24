import { Popover, PopoverItem } from '@/components/popover';
import {
    termCount,
    withTerm,
    withText,
    withoutTerm,
    type ParsedQuery,
} from '@/lib/issue-query';
import type { Facets, SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { CircleQuestionMark, Search, X } from 'lucide-react';
import { forwardRef, useEffect, useState, type ReactNode } from 'react';

/**
 * The filter bar: chips and a text box that edit one query string.
 *
 * Both write the same canonical string, so a filter built by clicking and one typed by
 * hand are indistinguishable — which is what makes "save this as a view" trivial.
 */
export const QueryBar = forwardRef<
    HTMLInputElement,
    {
        query: ParsedQuery;
        facets?: Facets;
        onChange: (query: string) => void;
        children?: ReactNode;
    }
>(function QueryBar({ query, facets, onChange, children }, ref) {
    const { docsUrl } = usePage<SharedProps>().props;
    const [raw, setRaw] = useState(query.query);
    const [editing, setEditing] = useState(false);

    // Adopt query changes from elsewhere (chips, saved views, back button) unless the
    // person is mid-edit, which would yank the text out from under them.
    useEffect(() => {
        if (!editing) setRaw(query.query);
    }, [query.query, editing]);

    const active = termCount(query);

    return (
        <div className="mb-4 flex flex-wrap items-center gap-2">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    onChange(raw);
                }}
                className="relative w-full sm:w-auto"
            >
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-subtle" />
                <input
                    ref={ref}
                    value={raw}
                    onChange={(e) => setRaw(e.target.value)}
                    onFocus={() => setEditing(true)}
                    onBlur={() => setEditing(false)}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') {
                            setRaw(query.query);
                            e.currentTarget.blur();
                        }
                    }}
                    placeholder="is:open assignee:@me …"
                    aria-label="Filter issues"
                    spellCheck={false}
                    className="h-[30px] w-full rounded-lg sm:w-80 border border-border bg-raised pr-2 pl-8 font-mono text-xs text-ink placeholder:text-ink-subtle focus:border-accent focus:outline-none"
                />

                {/*
                    Next to the box rather than in a menu: the query language is the
                    least discoverable thing here, and somebody staring at
                    `is:open assignee:@me` is exactly who needs the page explaining it.
                */}
                <a
                    href={`${docsUrl}/query-language`}
                    target="_blank"
                    rel="noreferrer"
                    title="Query language"
                    aria-label="How the query language works"
                    className="text-ink-subtle transition hover:text-ink"
                >
                    <CircleQuestionMark className="size-3.5" />
                </a>
            </form>

            <Chip label="Status" value={query.state === 'open' ? null : query.state}>
                {(close) =>
                    (['open', 'closed', 'any'] as const).map((state) => (
                        <PopoverItem
                            key={state}
                            selected={query.state === state}
                            onSelect={() => {
                                close();
                                onChange(withTerm(query, 'is', state));
                            }}
                        >
                            <span className="capitalize">{state}</span>
                        </PopoverItem>
                    ))
                }
            </Chip>

            <Chip
                label="Project"
                value={
                    facets?.projects.find((p) => p.slug === query.include.project?.[0])?.key ??
                    null
                }
                onClear={() => onChange(withoutTerm(query, 'project'))}
            >
                {(close) =>
                    (facets?.projects ?? []).map((project) => (
                        <PopoverItem
                            key={project.id}
                            selected={query.include.project?.[0] === project.slug}
                            onSelect={() => {
                                close();
                                onChange(withTerm(query, 'project', project.slug));
                            }}
                        >
                            <span className="font-mono text-[11px] text-ink-subtle">
                                {project.key}
                            </span>
                            <span className="truncate">{project.name}</span>
                        </PopoverItem>
                    ))
                }
            </Chip>

            <Chip
                label="Assignee"
                value={assigneeLabel(query, facets)}
                onClear={() => onChange(withoutTerm(query, 'assignee'))}
            >
                {(close) => (
                    <>
                        <PopoverItem
                            selected={query.include.assignee?.[0] === '@me'}
                            onSelect={() => {
                                close();
                                onChange(withTerm(query, 'assignee', '@me'));
                            }}
                        >
                            Assigned to me
                        </PopoverItem>
                        <PopoverItem
                            selected={query.include.no?.includes('assignee')}
                            onSelect={() => {
                                close();
                                onChange(withTerm(query, 'no', 'assignee'));
                            }}
                        >
                            Unassigned
                        </PopoverItem>
                        {(facets?.members ?? []).map((member) => (
                            <PopoverItem
                                key={member.id}
                                selected={query.include.assignee?.[0] === String(member.id)}
                                onSelect={() => {
                                    close();
                                    onChange(withTerm(query, 'assignee', String(member.id)));
                                }}
                            >
                                <span className="truncate">{member.name}</span>
                            </PopoverItem>
                        ))}
                    </>
                )}
            </Chip>

            <Chip
                label="Label"
                value={query.include.label?.join(', ') ?? null}
                onClear={() => onChange(withoutTerm(query, 'label'))}
            >
                {(close) =>
                    (facets?.labels ?? []).length === 0 ? (
                        <p className="px-2 py-1.5 text-xs text-ink-subtle">No labels yet.</p>
                    ) : (
                        (facets?.labels ?? []).map((label) => {
                            const on = query.include.label?.includes(label.name) ?? false;

                            return (
                                <PopoverItem
                                    key={label.id}
                                    selected={on}
                                    onSelect={() => {
                                        close();
                                        onChange(
                                            on
                                                ? withoutTerm(query, 'label', label.name)
                                                : withTerm(query, 'label', label.name),
                                        );
                                    }}
                                >
                                    <span
                                        aria-hidden
                                        className="size-2 rounded-full"
                                        style={{ backgroundColor: label.color }}
                                    />
                                    <span className="truncate">{label.name}</span>
                                </PopoverItem>
                            );
                        })
                    )
                }
            </Chip>

            <Chip
                label="Priority"
                value={
                    facets?.priorities.find(
                        (p) => p.label.toLowerCase() === query.include.priority?.[0],
                    )?.label ?? query.include.priority?.[0] ?? null
                }
                onClear={() => onChange(withoutTerm(query, 'priority'))}
            >
                {(close) =>
                    (facets?.priorities ?? []).map((option) => {
                        const token = option.label.split(' ')[0].toLowerCase();

                        return (
                            <PopoverItem
                                key={option.value}
                                selected={query.include.priority?.[0] === token}
                                onSelect={() => {
                                    close();
                                    onChange(withTerm(query, 'priority', token));
                                }}
                            >
                                {option.label}
                            </PopoverItem>
                        );
                    })
                }
            </Chip>

            {active > 0 && (
                <button
                    type="button"
                    onClick={() => onChange(withText({ ...query, include: {}, exclude: {} }, query.text))}
                    className="rounded-lg px-2 py-1 text-xs text-ink-subtle transition hover:text-ink"
                >
                    Clear {active} filter{active === 1 ? '' : 's'}
                </button>
            )}

            <div className="ml-auto flex items-center gap-2">{children}</div>
        </div>
    );
});

function assigneeLabel(query: ParsedQuery, facets?: Facets): string | null {
    if (query.include.no?.includes('assignee')) return 'Unassigned';

    const value = query.include.assignee?.[0];
    if (!value) return null;
    if (value === '@me') return 'Me';

    return facets?.members.find((m) => String(m.id) === value)?.name ?? value;
}

function Chip({
    label,
    value,
    onClear,
    children,
}: {
    label: string;
    value: string | null;
    onClear?: () => void;
    children: (close: () => void) => ReactNode;
}) {
    return (
        <span className="flex items-center">
            <Popover
                label={label}
                trigger={() => (
                    <span
                        className={`flex max-w-48 items-center gap-1.5 truncate rounded-lg border px-2 py-1 text-xs transition ${
                            value
                                ? 'border-accent/40 bg-accent-soft text-accent'
                                : 'border-border text-ink-muted hover:text-ink'
                        }`}
                    >
                        {label}
                        {value && <span className="truncate font-medium">{value}</span>}
                    </span>
                )}
            >
                {children}
            </Popover>

            {value && onClear && (
                <button
                    type="button"
                    onClick={onClear}
                    aria-label={`Clear ${label} filter`}
                    className="-ml-1 rounded p-1 text-accent transition hover:bg-surface"
                >
                    <X className="size-3" />
                </button>
            )}
        </span>
    );
}
