import {
    TimelineChart,
    type ColourBy,
    type TimelineAxis,
    type TimelineRow,
} from '@/components/timeline-chart';
import { AppLayout } from '@/layouts/app-layout';
import { withTerm, withoutTerm, type ParsedQuery } from '@/lib/issue-query';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface UndatedRow {
    key: string;
    version: string;
    title: string;
    project: string;
    status: string;
    assignee: string | null;
    open: boolean;
    phase: string | null;
}

export default function TimelinePage({
    rows,
    undated,
    truncated,
    axis,
    query,
    projects,
    editable = false,
    group = 'phase',
    groupings = ['phase', 'none'],
}: {
    rows: TimelineRow[];
    undated: UndatedRow[];
    truncated: boolean;
    axis: TimelineAxis;
    query: ParsedQuery;
    projects: { id: number; name: string; slug: string }[];
    /** Staff drag; a client reads, one project at a time. */
    editable?: boolean;
    group?: string;
    /** The groupings this viewer may choose: fewer for a client. */
    groupings?: string[];
}) {
    const [raw, setRaw] = useState(query.query);

    // Adopt a query changed elsewhere — the project dropdown, the back button —
    // without yanking the text out from under somebody mid-edit.
    const [editing, setEditing] = useState(false);

    useEffect(() => {
        if (!editing) setRaw(query.query);
    }, [query.query, editing]);

    function apply(changes: { q?: string; from?: string; to?: string; group?: string }) {
        const next: Record<string, string> = {
            q: changes.q ?? query.query,
            from: changes.from ?? axis.from,
            to: changes.to ?? axis.to,
            // The default is left out of the URL, so a plain link stays plain.
            group: (changes.group ?? group) === 'phase' ? '' : (changes.group ?? group),
        };

        Object.keys(next).forEach((key) => next[key] === '' && delete next[key]);

        router.get('/timeline', next, { preserveState: true, preserveScroll: true });
    }

    const control = 'rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-ink';

    // Refused because somebody else changed the issue first; the reload that comes
    // with the refusal already shows their version.
    const errors = usePage().props.errors as Record<string, string>;
    // A refused drag, or a link that would make a loop.
    const conflict = errors?.schedule ?? errors?.key;

    // Per-person display choices, remembered in this browser only.
    const [allLinks, setAllLinks] = useRemembered('timeline.allLinks', true);
    const [shiftDependents, setShiftDependents] = useRemembered('timeline.shiftDependents', false);
    const [colourBy, setColourBy] = useState<ColourBy>(() => {
        try {
            const stored = window.localStorage.getItem('timeline.colourBy');

            return stored === 'status' || stored === 'assignee' ? stored : 'state';
        } catch {
            return 'state';
        }
    });

    function chooseColour(next: ColourBy) {
        setColourBy(next);

        try {
            window.localStorage.setItem('timeline.colourBy', next);
        } catch {
            // Not kept; it lasts for this page.
        }
    }

    const reload = { preserveScroll: true, preserveState: true, only: ['rows', 'undated', 'errors', 'flash'] };

    function schedule(key: string, dates: { start_on: string | null; due_on: string | null }, version: string | null) {
        router.patch(
            `/issues/${key}/schedule`,
            { ...dates, version, shift_dependents: shiftDependents },
            reload,
        );
    }

    function link(blocker: string, blocked: string) {
        router.post(`/issues/${blocker}/relations`, { key: blocked, type: 'blocks' }, reload);
    }

    function unlink(blocker: string, blocked: string) {
        router.delete(`/issues/${blocker}/relations`, { data: { key: blocked, type: 'blocks' }, ...reload });
    }
    const project = query.include.project?.[0] ?? '';

    return (
        <AppLayout title="Timeline">
            <Head title="Timeline" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ q: raw });
                        }}
                    >
                        <input
                            value={raw}
                            onChange={(e) => setRaw(e.target.value)}
                            onFocus={() => setEditing(true)}
                            onBlur={() => setEditing(false)}
                            placeholder="is:open assignee:@me …"
                            aria-label="Filter issues"
                            spellCheck={false}
                            className="w-72 rounded-lg border border-border bg-surface px-2.5 py-1.5 font-mono text-xs text-ink placeholder:text-ink-subtle"
                        />
                    </form>

                    {/*
                        The dropdown edits the query string rather than sending a
                        project_id of its own. One string is the whole filter state
                        here as much as on the issue list — two representations of the
                        same filter is how a URL and a saved view drift apart.
                    */}
                    <select
                        value={project}
                        aria-label="Project"
                        onChange={(e) =>
                            apply({
                                q: e.target.value
                                    ? withTerm(query, 'project', e.target.value)
                                    : withoutTerm(query, 'project'),
                            })
                        }
                        className={control}
                    >
                        {/* A client is always looking at one project. */}
                        {editable && <option value="">Every project</option>}
                        {projects.map((p) => (
                            <option key={p.id} value={p.slug}>
                                {p.name}
                            </option>
                        ))}
                    </select>

                    <input
                        type="date"
                        value={axis.from}
                        aria-label="From"
                        onChange={(e) => apply({ from: e.target.value })}
                        className={control}
                    />
                    <span className="text-sm text-ink-subtle">to</span>
                    <input
                        type="date"
                        value={axis.to}
                        aria-label="To"
                        onChange={(e) => apply({ to: e.target.value })}
                        className={control}
                    />

                    {groupings.length > 1 && (
                        <select
                            value={group}
                            aria-label="Group by"
                            onChange={(e) => apply({ group: e.target.value })}
                            className={control}
                        >
                            {groupings.map((g) => (
                                <option key={g} value={g}>
                                    {GROUP_LABELS[g] ?? g}
                                </option>
                            ))}
                        </select>
                    )}

                    {/* A client's rows carry no status colour or person to colour by. */}
                    {editable && (
                        <select
                            value={colourBy}
                            aria-label="Colour by"
                            onChange={(e) => chooseColour(e.target.value as ColourBy)}
                            className={control}
                        >
                            <option value="state">Colour: open, closed, late</option>
                            <option value="status">Colour: by status</option>
                            <option value="assignee">Colour: by person</option>
                        </select>
                    )}

                    {/* A plan is mostly ahead of you, so the presets are too. */}
                    {[
                        { label: 'A month', back: 7, forward: 30 },
                        { label: 'A quarter', back: 14, forward: 75 },
                        { label: 'A year', back: 30, forward: 335 },
                    ].map((preset) => (
                        <button
                            key={preset.label}
                            type="button"
                            onClick={() => {
                                const from = new Date();
                                const to = new Date();
                                from.setDate(from.getDate() - preset.back);
                                to.setDate(to.getDate() + preset.forward);

                                apply({
                                    from: from.toISOString().slice(0, 10),
                                    to: to.toISOString().slice(0, 10),
                                });
                            }}
                            className="rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                        >
                            {preset.label}
                        </button>
                    ))}
                </div>

                {conflict && (
                    <p role="alert" className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                        {conflict}
                    </p>
                )}

                <TimelineChart
                    rows={rows}
                    axis={axis}
                    editable={editable}
                    onReschedule={editable ? (row, dates) => schedule(row.key, dates, row.version) : undefined}
                    // Dropped on a day: a one-day bar there, ready to be stretched.
                    onPlace={editable ? (key, version, day) => schedule(key, { start_on: day, due_on: day }, version) : undefined}
                    onLink={editable ? link : undefined}
                    onUnlink={editable ? unlink : undefined}
                    allLinks={allLinks}
                    colourBy={editable ? colourBy : 'state'}
                    owners={editable}
                />

                <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-ink-muted">
                    <label className="flex items-center gap-1.5">
                        <input type="checkbox" checked={allLinks} onChange={(e) => setAllLinks(e.target.checked)} />
                        Show every dependency, not only late ones
                    </label>
                    {editable && (
                        <label className="flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                checked={shiftDependents}
                                onChange={(e) => setShiftDependents(e.target.checked)}
                            />
                            When a bar moves later, move the work waiting on it too
                        </label>
                    )}
                </div>

                {editable && (
                    <p className="text-xs text-ink-subtle">
                        Drag a bar to move it, or either end to change its dates. Drag the dot
                        after a bar onto another issue to say it blocks that one; click a line to
                        remove it. With a bar selected, the arrow keys move it a day and Shift
                        moves its due date.
                    </p>
                )}

                <div className="flex flex-wrap gap-4 text-xs text-ink-muted">
                    {editable && colourBy !== 'state' ? (
                        <span>
                            Bars are coloured {colourBy === 'status' ? 'by status' : 'by person'}; closed
                            work is faded and late work is outlined in red.
                        </span>
                    ) : (
                        <>
                            <Key className="bg-accent" label="Open" />
                            <Key className="bg-success" label="Closed" />
                            <Key className="bg-danger" label="Overdue" />
                        </>
                    )}
                    <span className="flex items-center gap-1.5">
                        <span className="size-2 rotate-45 bg-ink-muted" />
                        One date only — a marker, not a span
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-0.5 w-4 bg-ink-muted" />
                        A parent spanning its subtasks
                    </span>
                    {rows.some((row) => row.progress) && (
                        <span className="flex items-center gap-1.5">
                            <span className="h-1.5 w-4 rounded-full bg-gradient-to-r from-success from-50% to-ink-muted/30 to-50%" />
                            A phase, filled in as its issues are done
                        </span>
                    )}
                    <span className="flex items-center gap-1.5">
                        <span className="h-0.5 w-4 bg-ink-subtle" />
                        A dependency
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-0.5 w-4 bg-danger" />
                        A blocker that does not finish in time
                    </span>
                </div>

                {truncated && (
                    <p className="text-xs text-ink-subtle">
                        Showing the first 500 matching issues. Narrow the filter to see the rest.
                    </p>
                )}

                {undated.length > 0 && (
                    <section className="rounded-xl border border-border p-4">
                        <h2 className="text-sm font-semibold text-ink">No dates</h2>
                        <p className="mt-1 text-xs text-ink-subtle">
                            {editable
                                ? 'Matched the filter, but has neither a start nor a due date. Drag one onto the chart to give it a day, then stretch it to the length it needs.'
                                : 'Not scheduled yet.'}
                        </p>
                        <ul className="mt-3 divide-y divide-border">
                            {undated.map((issue) => (
                                <li
                                    key={issue.key}
                                    draggable={editable}
                                    onDragStart={(event) => {
                                        event.dataTransfer.setData(
                                            'application/x-buggie-issue',
                                            JSON.stringify({ key: issue.key, version: issue.version }),
                                        );
                                        event.dataTransfer.effectAllowed = 'move';
                                    }}
                                    title={editable ? 'Drag onto the chart to give it dates' : undefined}
                                    className={`flex items-center gap-3 py-2 ${editable ? 'cursor-grab active:cursor-grabbing' : ''}`}
                                >
                                    <Link
                                        href={`/issues/${issue.key}`}
                                        className="shrink-0 font-mono text-xs text-accent"
                                    >
                                        {issue.key}
                                    </Link>
                                    <span className="min-w-0 flex-1 truncate text-sm text-ink">
                                        {issue.title}
                                    </span>
                                    {issue.phase && (
                                        <span className="shrink-0 rounded bg-surface px-1.5 py-0.5 text-[11px] text-ink-muted">
                                            {issue.phase}
                                        </span>
                                    )}
                                    <span className="shrink-0 text-xs text-ink-subtle">
                                        {issue.project}
                                    </span>
                                    <span className="shrink-0 text-xs text-ink-subtle">
                                        {issue.status}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

const GROUP_LABELS: Record<string, string> = {
    phase: 'Group: by phase',
    project: 'Group: by project',
    assignee: 'Group: by person',
    status: 'Group: by status',
    none: 'No grouping',
};

/** A setting kept in this browser. Storage can be missing or refuse; then it is not kept. */
function useRemembered(key: string, fallback: boolean): [boolean, (value: boolean) => void] {
    const [value, setValue] = useState(() => {
        try {
            const stored = window.localStorage.getItem(key);

            return stored === null ? fallback : stored === '1';
        } catch {
            return fallback;
        }
    });

    function set(next: boolean) {
        setValue(next);

        try {
            window.localStorage.setItem(key, next ? '1' : '0');
        } catch {
            // Private window or blocked storage: it lasts for this page only.
        }
    }

    return [value, set];
}

function Key({ className, label }: { className: string; label: string }) {
    return (
        <span className="flex items-center gap-1.5">
            <span className={`size-2 rounded-sm ${className}`} />
            {label}
        </span>
    );
}
