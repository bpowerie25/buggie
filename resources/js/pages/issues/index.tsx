import { Button } from '@/components/button';
import { IssueBoard } from '@/components/issue-board';
import {
    Avatar,
    LabelPill,
    PriorityBars,
    StatusDot,
    TypeIcon,
    relativeTime,
} from '@/components/issue-bits';
import { PopoverItem } from '@/components/popover';
import { QueryBar } from '@/components/query-bar';
import { useHotkeys } from '@/hooks/use-hotkeys';
import { withTerm, type ParsedQuery } from '@/lib/issue-query';
import { AppLayout } from '@/layouts/app-layout';
import type { Facets, IssueRow, IssueStatus, SavedView, SharedProps } from '@/types';
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useVirtualizer } from '@tanstack/react-virtual';
import {
    Bookmark,
    ChevronRight,
    Download,
    LayoutGrid,
    List,
    Plus,
    Undo2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

// Narrower than RequestPayload, which allows FormData and so cannot be nested
// inside the bulk request body.
type Changes = Record<string, FormDataConvertible>;

type GroupBy = 'status' | 'assignee' | 'priority' | 'project';
type MenuKind = 'status' | 'assignee' | 'priority';

/** A flattened list of headers and rows, so one virtualiser covers the whole thing. */
type Line =
    | { kind: 'header'; key: string; name: string; status: IssueStatus | null; count: number }
    | { kind: 'row'; key: string; issue: IssueRow };

const ROW_HEIGHT = 37;
const HEADER_HEIGHT = 33;

export default function IssuesIndex({
    issues,
    query,
    layout,
    groupBy,
    facets,
    views,
}: {
    issues: IssueRow[];
    query: ParsedQuery;
    layout: 'list' | 'board';
    groupBy: GroupBy;
    facets?: Facets;
    views: SavedView[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const editable = auth.role !== 'client';

    const searchRef = useRef<HTMLInputElement>(null);
    const scrollRef = useRef<HTMLDivElement>(null);

    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [active, setActive] = useState(0);
    const [menu, setMenu] = useState<{ key: string; kind: MenuKind } | null>(null);

    /**
     * Optimistic overrides, keyed by issue key. An inline edit paints immediately and
     * the entry is dropped when the server's version of the row arrives.
     */
    const [overrides, setOverrides] = useState<Record<string, Partial<IssueRow>>>({});

    /**
     * The last thing that happened, and how to take it back.
     *
     * Held in the page rather than on the server. Undo here is a seconds-scale
     * affordance for a click somebody did not mean — the row's previous values are
     * already known, because that is what the optimistic update replaced, so undoing
     * is sending them back. It deliberately does not survive a reload: a button that
     * offers to undo something from yesterday is making a promise about history that
     * nothing here keeps.
     */
    const [undo, setUndo] = useState<{ label: string; run: () => void } | null>(null);

    useEffect(() => setOverrides({}), [issues]);

    const rows = useMemo(
        () => issues.map((issue) => ({ ...issue, ...overrides[issue.key] })),
        [issues, overrides],
    );

    const navigate = useCallback(
        (next: string, extra: Record<string, string> = {}) => {
            router.get(
                '/issues',
                { q: next, layout, group: groupBy, ...extra },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [layout, groupBy],
    );

    const patch = useCallback(
        (
            issue: IssueRow,
            changes: Changes,
            optimistic: Partial<IssueRow>,
            // What it was, so it can be put back. Omitted by callers with nothing
            // meaningful to reverse.
            undoable?: { label: string; changes: Changes; optimistic: Partial<IssueRow> },
        ) => {
            if (undoable) {
                setUndo({
                    label: undoable.label,
                    run: () => patch(issue, undoable.changes, undoable.optimistic),
                });
            }

            setOverrides((current) => ({
                ...current,
                [issue.key]: { ...current[issue.key], ...optimistic },
            }));

            router.patch(`/issues/${issue.key}`, changes, {
                preserveScroll: true,
                preserveState: true,
                only: ['issues', 'flash'],
                // Roll the row back rather than leaving a lie on screen.
                onError: () =>
                    setOverrides((current) => {
                        const next = { ...current };
                        delete next[issue.key];
                        return next;
                    }),
            });
        },
        [],
    );

    const groups = useMemo(() => groupIssues(rows, groupBy), [rows, groupBy]);

    /**
     * What dropping a card in a column means, which depends entirely on what the
     * columns are. Only the page knows that, so the board reports the column and
     * this decides.
     */
    /**
     * Put a card where it was dropped: in the right column, and at the right height
     * within it.
     *
     * The two are separate writes because they are separate questions. Moving a card
     * to "In Progress" is a change to the issue and belongs in its history; moving it
     * three cards up is not, and an activity entry for every drag would bury the
     * history that matters.
     */
    function rankAfterDrop(issue: IssueRow, columnName: string, beforeKey: string | null) {
        const cards = groups.find((g) => g.name === columnName)?.issues ?? [];
        const without = cards.filter((c) => c.key !== issue.key);

        const beforeIndex =
            beforeKey === null ? without.length : without.findIndex((c) => c.key === beforeKey);

        const after = without[beforeIndex - 1]?.key ?? null;
        const before = without[beforeIndex]?.key ?? null;

        // Already there. Nothing to say, and saying it would be a wasted round trip
        // on every click that happens to travel four pixels.
        if (after === null && before === null) return;

        router.patch(
            `/issues/${issue.key}/rank`,
            { after, before },
            { preserveScroll: true, preserveState: true, only: ['issues'] },
        );
    }

    function moveToColumn(issue: IssueRow, columnName: string, beforeKey: string | null = null) {
        // Dropped back in the column it came from: a reorder, not a move. This used
        // to be a plain return, which is why dragging a card up its own column did
        // nothing at all.
        if (groupBy === 'assignee') {
            if ((issue.assignee?.name ?? 'Unassigned') === columnName) {
                return rankAfterDrop(issue, columnName, beforeKey);
            }

            const person = (facets?.members ?? []).find((m) => m.name === columnName);

            // "Unassigned" is a real column and a real destination, not a failure to
            // find somebody.
            if (columnName === 'Unassigned') {
                patch(issue, { assignee_id: null }, { assignee: null });
            } else if (person) {
                patch(issue, { assignee_id: person.id }, { assignee: person });
            }

            rankAfterDrop(issue, columnName, beforeKey);

            return;
        }

        if (groupBy === 'priority') {
            if (issue.priority_label === columnName) {
                return rankAfterDrop(issue, columnName, beforeKey);
            }

            const priority = (facets?.priorities ?? []).find((p) => p.label === columnName);

            if (priority) {
                patch(
                    issue,
                    { priority: priority.value },
                    {
                        priority: priority.value,
                        priority_label: priority.label,
                        priority_color: priority.color,
                    },
                );
            }

            rankAfterDrop(issue, columnName, beforeKey);

            return;
        }

        if (issue.status.name === columnName) {
            return rankAfterDrop(issue, columnName, beforeKey);
        }

        // Statuses belong to projects, so a board spanning projects merges columns by
        // name and resolves to the status with that name in the dropped issue's own
        // project. That keeps a cross-project board usable without pretending every
        // project shares one workflow.
        const status = (facets?.statuses_by_project[issue.project.id] ?? []).find(
            (s) => s.name === columnName,
        );

        if (status) {
            patch(issue, { status_id: status.id }, { status });
            rankAfterDrop(issue, columnName, beforeKey);
        }
    }


    const lines = useMemo<Line[]>(() => {
        const out: Line[] = [];

        for (const group of groups) {
            out.push({
                kind: 'header',
                key: `h:${group.name}`,
                name: group.name,
                status: group.status,
                count: group.issues.length,
            });

            if (!collapsed[group.name]) {
                for (const issue of group.issues) {
                    out.push({ kind: 'row', key: issue.key, issue });
                }
            }
        }

        return out;
    }, [groups, collapsed]);

    const rowLines = useMemo(
        () => lines.filter((line): line is Extract<Line, { kind: 'row' }> => line.kind === 'row'),
        [lines],
    );

    const virtualizer = useVirtualizer({
        count: lines.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: (i) => (lines[i].kind === 'header' ? HEADER_HEIGHT : ROW_HEIGHT),
        overscan: 12,
    });

    const activeIssue = rowLines[active]?.issue;

    const move = useCallback(
        (delta: number) => {
            setActive((current) => {
                const next = Math.max(0, Math.min(rowLines.length - 1, current + delta));
                const lineIndex = lines.findIndex(
                    (line) => line.kind === 'row' && line.key === rowLines[next]?.key,
                );
                if (lineIndex >= 0) virtualizer.scrollToIndex(lineIndex, { align: 'auto' });
                return next;
            });
            setMenu(null);
        },
        [rowLines, lines, virtualizer],
    );

    useHotkeys({
        j: () => move(1),
        ArrowDown: () => move(1),
        k: () => move(-1),
        ArrowUp: () => move(-1),
        Enter: () => activeIssue && router.visit(`/issues/${activeIssue.key}`),
        '/': () => searchRef.current?.focus(),
        e: () => editable && activeIssue && setMenu({ key: activeIssue.key, kind: 'status' }),
        a: () => editable && activeIssue && setMenu({ key: activeIssue.key, kind: 'assignee' }),
        p: () => editable && activeIssue && setMenu({ key: activeIssue.key, kind: 'priority' }),
        x: () =>
            activeIssue &&
            setSelected((current) => {
                const next = new Set(current);
                next.has(activeIssue.key) ? next.delete(activeIssue.key) : next.add(activeIssue.key);
                return next;
            }),
        Escape: () => {
            setMenu(null);
            setSelected(new Set());
        },
        'g b': () => router.get('/issues', { q: query.query, layout: 'board', group: groupBy }),
        'g l': () => router.get('/issues', { q: query.query, layout: 'list', group: groupBy }),
        'g a': () => navigate(withTerm(query, 'assignee', '@me')),
    });

    function bulk(changes: Changes, describe?: string) {
        const keys = [...selected];

        /*
         * The previous value of every row, captured before the change.
         *
         * A bulk edit is the thing most worth being able to take back and the most
         * tedious to reverse by hand — forty issues moved to the wrong status is
         * forty clicks otherwise. Each row goes back to what it had rather than to a
         * single shared value, because they did not all start the same.
         */
        const before = rows
            .filter((row) => selected.has(row.key))
            .map((row) => ({ key: row.key, status: row.status, assignee: row.assignee }));

        if (describe && 'status_id' in changes) {
            setUndo({
                label: `${keys.length} issue${keys.length === 1 ? '' : 's'} moved to ${describe}`,
                run: () =>
                    before.forEach((row) =>
                        router.patch(
                            `/issues/${row.key}`,
                            { status_id: row.status.id },
                            { preserveScroll: true, preserveState: true, only: ['issues'] },
                        ),
                    ),
            });
        }

        router.patch(
            '/issues/bulk',
            { keys, changes },
            {
                preserveScroll: true,
                only: ['issues', 'flash'],
                onSuccess: () => setSelected(new Set()),
            },
        );
    }

    const actions = (
        <>
            <div className="flex rounded-lg border border-border p-0.5">
                {(['list', 'board'] as const).map((option) => {
                    const Icon = option === 'list' ? List : LayoutGrid;

                    return (
                        <button
                            key={option}
                            type="button"
                            aria-label={`${option} view`}
                            aria-pressed={layout === option}
                            onClick={() =>
                                router.get('/issues', { q: query.query, layout: option, group: groupBy })
                            }
                            className={`rounded-md p-1 transition ${
                                layout === option
                                    ? 'bg-accent-soft text-accent'
                                    : 'text-ink-subtle hover:text-ink'
                            }`}
                        >
                            <Icon className="size-4" />
                        </button>
                    );
                })}
            </div>

            <Link href="/issues/create">
                <Button size="sm">
                    <Plus className="size-4" />
                    New issue
                </Button>
            </Link>
        </>
    );

    return (
        <AppLayout title="Issues" actions={actions} views={views} activeQuery={query.query}>
            <Head title="Issues" />

            <QueryBar ref={searchRef} query={query} facets={facets} onChange={navigate}>
                <SaveViewButton query={query.query} layout={layout} groupBy={groupBy} />

                {/*
                    A plain link, not a router visit: this is a file download, and
                    Inertia would try to render the CSV as a page. It carries the
                    current query, so what you export is what you are looking at.
                */}
                <a
                    href={`/issues/export${query.query ? `?q=${encodeURIComponent(query.query)}` : ''}`}
                    title="Download these issues as CSV"
                    className="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                >
                    <Download className="size-3.5" />
                    Export
                </a>

                <span className="text-xs text-ink-subtle">
                    {rows.length} issue{rows.length === 1 ? '' : 's'}
                </span>
            </QueryBar>

            {selected.size > 0 && editable && (
                <div className="mb-3 flex items-center gap-2 rounded-lg border border-accent/40 bg-accent-soft px-3 py-2 text-xs">
                    <span className="font-medium text-accent">{selected.size} selected</span>

                    <BulkMenu label="Status" >
                        {(close) =>
                            uniqueStatuses(facets).map((status) => (
                                <PopoverItem
                                    key={status.name}
                                    onSelect={() => {
                                        close();
                                        bulk({ status_id: status.id }, status.name);
                                    }}
                                >
                                    <StatusDot status={status} />
                                    {status.name}
                                </PopoverItem>
                            ))
                        }
                    </BulkMenu>

                    <BulkMenu label="Assignee">
                        {(close) => (
                            <>
                                <PopoverItem onSelect={() => { close(); bulk({ assignee_id: null }); }}>
                                    Unassigned
                                </PopoverItem>
                                {(facets?.members ?? []).map((member) => (
                                    <PopoverItem
                                        key={member.id}
                                        onSelect={() => { close(); bulk({ assignee_id: member.id }); }}
                                    >
                                        <Avatar name={member.name} />
                                        {member.name}
                                    </PopoverItem>
                                ))}
                            </>
                        )}
                    </BulkMenu>

                    <BulkMenu label="Priority">
                        {(close) =>
                            (facets?.priorities ?? []).map((option) => (
                                <PopoverItem
                                    key={option.value}
                                    onSelect={() => { close(); bulk({ priority: option.value }); }}
                                >
                                    {option.label}
                                </PopoverItem>
                            ))
                        }
                    </BulkMenu>

                    <button
                        type="button"
                        onClick={() => setSelected(new Set())}
                        className="ml-auto text-ink-muted hover:text-ink"
                    >
                        Clear
                    </button>
                </div>
            )}

            {/*
                One level of undo, and it disappears on the next navigation.
                
                Deliberately not a history stack: the useful window for "that was not
                what I meant" is about five seconds, and a stack invites somebody to
                trust it for longer than it is true.
            */}
            {undo && (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-raised px-3 py-2 text-sm">
                    <span className="text-ink-muted">{undo.label}</span>
                    <button
                        type="button"
                        onClick={() => {
                            undo.run();
                            setUndo(null);
                        }}
                        className="ml-auto flex items-center gap-1.5 font-medium text-accent hover:underline"
                    >
                        <Undo2 className="size-3.5" />
                        Undo
                    </button>
                    <button
                        type="button"
                        aria-label="Dismiss"
                        onClick={() => setUndo(null)}
                        className="rounded p-1 text-ink-subtle transition hover:text-ink"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            {rows.length === 0 ? (
                <EmptyState query={query} />
            ) : layout === 'board' ? (
                <IssueBoard
                    columns={groups}
                    // Dragging cannot express "move to another project": that changes
                    // the issue's key and its number. Cards are not draggable there
                    // rather than draggable and inert.
                    editable={editable && groupBy !== 'project'}
                    onDropInColumn={(issue, columnName, beforeKey) =>
                        moveToColumn(issue, columnName, beforeKey)
                    }
                />
            ) : (
                <div
                    ref={scrollRef}
                    className="max-h-[calc(100vh-11rem)] overflow-auto rounded-xl border border-border bg-raised"
                >
                    <div style={{ height: virtualizer.getTotalSize(), position: 'relative' }}>
                        {virtualizer.getVirtualItems().map((item) => {
                            const line = lines[item.index];

                            return (
                                <div
                                    key={line.key}
                                    ref={virtualizer.measureElement}
                                    data-index={item.index}
                                    style={{
                                        position: 'absolute',
                                        top: 0,
                                        left: 0,
                                        width: '100%',
                                        transform: `translateY(${item.start}px)`,
                                    }}
                                >
                                    {line.kind === 'header' ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setCollapsed((c) => ({ ...c, [line.name]: !c[line.name] }))
                                            }
                                            aria-expanded={!collapsed[line.name]}
                                            className="flex w-full items-center gap-2 border-b border-border bg-surface px-4 py-2 text-left"
                                        >
                                            <ChevronRight
                                                className={`size-3.5 text-ink-subtle transition-transform ${
                                                    collapsed[line.name] ? '' : 'rotate-90'
                                                }`}
                                            />
                                            {line.status && <StatusDot status={line.status} />}
                                            <span className="text-xs font-medium text-ink">{line.name}</span>
                                            <span className="text-xs text-ink-subtle">{line.count}</span>
                                        </button>
                                    ) : (
                                        <Row
                                            issue={line.issue}
                                            active={activeIssue?.key === line.issue.key}
                                            selected={selected.has(line.issue.key)}
                                            editable={editable}
                                            facets={facets}
                                            menu={menu?.key === line.issue.key ? menu.kind : null}
                                            onOpenMenu={(kind) =>
                                                setMenu({ key: line.issue.key, kind })
                                            }
                                            onCloseMenu={() => setMenu(null)}
                                            onFocus={() =>
                                                setActive(
                                                    rowLines.findIndex((r) => r.key === line.issue.key),
                                                )
                                            }
                                            onToggleSelect={() =>
                                                setSelected((current) => {
                                                    const next = new Set(current);
                                                    next.has(line.issue.key)
                                                        ? next.delete(line.issue.key)
                                                        : next.add(line.issue.key);
                                                    return next;
                                                })
                                            }
                                            onPatch={patch}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function Row({
    issue,
    active,
    selected,
    editable,
    facets,
    menu,
    onOpenMenu,
    onCloseMenu,
    onFocus,
    onToggleSelect,
    onPatch,
}: {
    issue: IssueRow;
    active: boolean;
    selected: boolean;
    editable: boolean;
    facets?: Facets;
    menu: MenuKind | null;
    onOpenMenu: (kind: MenuKind) => void;
    onCloseMenu: () => void;
    onFocus: () => void;
    onToggleSelect: () => void;
    onPatch: (
        issue: IssueRow,
        changes: Changes,
        optimistic: Partial<IssueRow>,
        undoable?: { label: string; changes: Changes; optimistic: Partial<IssueRow> },
    ) => void;
}) {
    const statuses = facets?.statuses_by_project[issue.project.id] ?? [];

    return (
        <div
            onMouseEnter={onFocus}
            className={`relative flex items-center gap-3 border-b border-border px-4 py-2 transition ${
                active ? 'bg-surface' : 'hover:bg-surface'
            } ${selected ? 'bg-accent-soft/40' : ''}`}
        >
            {active && (
                <span aria-hidden className="absolute inset-y-0 left-0 w-0.5 bg-accent" />
            )}

            <button
                type="button"
                role="checkbox"
                aria-checked={selected}
                aria-label={`Select ${issue.key}`}
                onClick={onToggleSelect}
                className={`size-3.5 shrink-0 rounded border transition ${
                    selected ? 'border-accent bg-accent' : 'border-border-strong'
                }`}
            />

            {/*
                The status is the control, not a decoration.
                
                Changing one issue's status already worked — by pressing `e` on the
                focused row — which is invisible to anybody who has not read the
                shortcut sheet. Everyone else selected the row and used the bulk
                toolbar to change exactly one thing. The menu, the patch and the
                optimistic update were all already here; only the click was missing.
            */}
            {editable ? (
                <button
                    type="button"
                    aria-label={`Change status of ${issue.key}, currently ${issue.status.name}`}
                    title={`${issue.status.name} — click to change`}
                    onClick={(e) => {
                        e.stopPropagation();
                        onOpenMenu('status');
                    }}
                    className="-m-1 shrink-0 rounded p-1 transition hover:bg-raised"
                >
                    <StatusDot status={issue.status} />
                </button>
            ) : (
                <StatusDot status={issue.status} />
            )}

            <TypeIcon type={issue.type} />

            <span className="w-20 shrink-0 font-mono text-xs text-ink-subtle">{issue.key}</span>

            <Link
                href={`/issues/${issue.key}`}
                className="min-w-0 flex-1 truncate text-sm text-ink hover:text-accent"
            >
                {issue.title}
            </Link>

            <span className="hidden shrink-0 rounded bg-surface px-1.5 py-0.5 text-[11px] text-ink-muted lg:inline">
                {issue.project.key}
            </span>

            <span className="hidden shrink-0 items-center gap-1 md:flex">
                {issue.labels.slice(0, 2).map((label) => (
                    <LabelPill key={label.id} label={label} />
                ))}
                {issue.labels.length > 2 && (
                    <span className="text-[11px] text-ink-subtle">+{issue.labels.length - 2}</span>
                )}
            </span>

            {editable ? (
                <button
                    type="button"
                    aria-label={`Change priority of ${issue.key}, currently ${issue.priority_label}`}
                    title={`${issue.priority_label} — click to change`}
                    onClick={(e) => {
                        e.stopPropagation();
                        onOpenMenu('priority');
                    }}
                    className="-m-1 shrink-0 rounded p-1 transition hover:bg-raised"
                >
                    <PriorityBars
                        priority={issue.priority}
                        color={issue.priority_color}
                        label={issue.priority_label}
                    />
                </button>
            ) : (
                <PriorityBars
                    priority={issue.priority}
                    color={issue.priority_color}
                    label={issue.priority_label}
                />
            )}

            <span className="hidden w-16 shrink-0 text-right text-[11px] text-ink-subtle sm:inline">
                {relativeTime(issue.updated_at)}
            </span>

            {/* The empty circle is a target too: "nobody has this" is the state you
                most often want to change. */}
            {editable ? (
                <button
                    type="button"
                    aria-label={`Change assignee of ${issue.key}, currently ${issue.assignee?.name ?? 'unassigned'}`}
                    title={`${issue.assignee?.name ?? 'Unassigned'} — click to change`}
                    onClick={(e) => {
                        e.stopPropagation();
                        onOpenMenu('assignee');
                    }}
                    className="-m-1 shrink-0 rounded p-1 transition hover:bg-raised"
                >
                    {issue.assignee ? (
                        <Avatar name={issue.assignee.name} />
                    ) : (
                        <span className="inline-block size-5 rounded-full border border-dashed border-border-strong" />
                    )}
                </button>
            ) : issue.assignee ? (
                <Avatar name={issue.assignee.name} />
            ) : (
                <span className="inline-block size-5 shrink-0 rounded-full border border-dashed border-border-strong" />
            )}

            {/* Opened by clicking a control above, or by e / a / p on the focused row. */}
            {menu && editable && (
                <div
                    role="menu"
                    className="absolute top-full right-4 z-30 max-h-72 w-56 overflow-y-auto rounded-lg border border-border bg-raised p-1 shadow-lg"
                >
                    {menu === 'status' &&
                        statuses.map((status) => (
                            <PopoverItem
                                key={status.id}
                                selected={status.id === issue.status.id}
                                onSelect={() => {
                                    onCloseMenu();
                                    onPatch(
                                        issue,
                                        { status_id: status.id },
                                        { status },
                                        {
                                            label: `${issue.key} moved to ${status.name}`,
                                            changes: { status_id: issue.status.id },
                                            optimistic: { status: issue.status },
                                        },
                                    );
                                }}
                            >
                                <StatusDot status={status} />
                                {status.name}
                            </PopoverItem>
                        ))}

                    {menu === 'assignee' && (
                        <>
                            <PopoverItem
                                selected={!issue.assignee}
                                onSelect={() => {
                                    onCloseMenu();
                                    onPatch(issue, { assignee_id: null }, { assignee: null });
                                }}
                            >
                                Unassigned
                            </PopoverItem>
                            {(facets?.members ?? []).map((member) => (
                                <PopoverItem
                                    key={member.id}
                                    selected={member.id === issue.assignee?.id}
                                    onSelect={() => {
                                        onCloseMenu();
                                        onPatch(issue, { assignee_id: member.id }, { assignee: member });
                                    }}
                                >
                                    <Avatar name={member.name} />
                                    {member.name}
                                </PopoverItem>
                            ))}
                        </>
                    )}

                    {menu === 'priority' &&
                        (facets?.priorities ?? []).map((option) => (
                            <PopoverItem
                                key={option.value}
                                selected={option.value === issue.priority}
                                onSelect={() => {
                                    onCloseMenu();
                                    onPatch(
                                        issue,
                                        { priority: option.value },
                                        {
                                            priority: option.value,
                                            priority_label: option.label,
                                            priority_color: option.color,
                                        },
                                    );
                                }}
                            >
                                <PriorityBars
                                    priority={option.value}
                                    color={option.color}
                                    label={option.label}
                                />
                                {option.label}
                            </PopoverItem>
                        ))}
                </div>
            )}
        </div>
    );
}

function BulkMenu({
    label,
    children,
}: {
    label: string;
    children: (close: () => void) => React.ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="rounded border border-accent/40 px-2 py-0.5 text-accent transition hover:bg-accent/10"
            >
                {label}
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-20" onClick={() => setOpen(false)} />
                    <div className="absolute top-full left-0 z-30 mt-1 max-h-72 w-56 overflow-y-auto rounded-lg border border-border bg-raised p-1 shadow-lg">
                        {children(() => setOpen(false))}
                    </div>
                </>
            )}
        </div>
    );
}

function SaveViewButton({
    query,
    layout,
    groupBy,
}: {
    query: string;
    layout: string;
    groupBy: string;
}) {
    const [naming, setNaming] = useState(false);
    const [name, setName] = useState('');
    const [shared, setShared] = useState(false);

    if (!naming) {
        return (
            <button
                type="button"
                onClick={() => setNaming(true)}
                className="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
            >
                <Bookmark className="size-3" />
                Save view
            </button>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    '/views',
                    { name, query, layout, group_by: groupBy, shared },
                    { onSuccess: () => { setNaming(false); setName(''); } },
                );
            }}
            className="flex items-center gap-1.5"
        >
            <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoFocus
                required
                placeholder="View name"
                onKeyDown={(e) => e.key === 'Escape' && setNaming(false)}
                className="h-[26px] w-32 rounded-lg border border-border bg-raised px-2 text-xs text-ink focus:border-accent focus:outline-none"
            />
            <label className="flex items-center gap-1 text-[11px] text-ink-muted">
                <input
                    type="checkbox"
                    checked={shared}
                    onChange={(e) => setShared(e.target.checked)}
                    className="rounded border-border-strong"
                />
                Shared
            </label>
            <Button size="sm" type="submit" disabled={!name}>
                Save
            </Button>
        </form>
    );
}

function EmptyState({ query }: { query: ParsedQuery }) {
    const filtered = query.query !== '' && query.query !== 'is:open';

    return (
        <div className="rounded-xl border border-dashed border-border-strong p-12 text-center">
            <p className="text-sm font-medium text-ink">Nothing here</p>
            <p className="mt-1 text-sm text-ink-muted">
                {filtered
                    ? 'No issues match this query.'
                    : 'No open issues. Enjoy it while it lasts.'}
            </p>
        </div>
    );
}

function uniqueStatuses(facets?: Facets): IssueStatus[] {
    const seen = new Map<string, IssueStatus>();

    for (const statuses of Object.values(facets?.statuses_by_project ?? {})) {
        for (const status of statuses) {
            if (!seen.has(status.name)) seen.set(status.name, status);
        }
    }

    return [...seen.values()];
}

function groupIssues(
    issues: IssueRow[],
    groupBy: GroupBy,
): { name: string; status: IssueStatus | null; issues: IssueRow[] }[] {
    const map = new Map<string, { name: string; status: IssueStatus | null; issues: IssueRow[] }>();

    for (const issue of issues) {
        const [name, status] = ((): [string, IssueStatus | null] => {
            switch (groupBy) {
                case 'assignee':
                    return [issue.assignee?.name ?? 'Unassigned', null];
                case 'priority':
                    return [issue.priority_label, null];
                case 'project':
                    return [issue.project.name, null];
                default:
                    return [issue.status.name, issue.status];
            }
        })();

        const existing = map.get(name);
        existing
            ? existing.issues.push(issue)
            : map.set(name, { name, status, issues: [issue] });
    }

    return [...map.values()].sort((a, b) => {
        if (groupBy !== 'status') return a.name.localeCompare(b.name);
        // Open columns first, then by the project's own ordering.
        const open = Number(b.status?.open ?? 0) - Number(a.status?.open ?? 0);
        return open !== 0 ? open : (a.status?.position ?? 0) - (b.status?.position ?? 0);
    });
}
