import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent as ReactPointerEvent } from 'react';

/**
 * The Gantt, drawn by hand.
 *
 * Same bargain as `charts.tsx`: a timeline library is 100KB and a fortnight of
 * fighting its theming for four shapes — a bar, a diamond, a bracket and an elbow.
 * These read the same CSS custom properties as everything else, so dark mode is free.
 *
 * Unlike the charts next door this is drawn in **pixels**, not in a stretched
 * viewBox. A Gantt scrolls sideways, so its horizontal scale is a property of the
 * date range rather than of however wide the browser happens to be, and text inside a
 * `preserveAspectRatio="none"` viewBox comes out smeared.
 *
 * Labels are HTML in a fixed column beside the SVG rather than `<text>` inside it:
 * they need links, truncation and a tooltip, all of which HTML already does.
 */

export interface TimelineRow {
    key: string;
    title: string;
    project: string;
    status: string;
    assignee: string | null;
    depth: number;
    start: string;
    end: string;
    start_on: string | null;
    due_on: string | null;
    /** `phase` is a header over the issues in one stage of the job, not an issue. */
    kind: 'bar' | 'milestone' | 'rollup' | 'phase';
    anchor: 'start' | 'due';
    open: boolean;
    overdue: boolean;
    children: number;
    estimate: string | null;
    blocked_by: string[];
    conflicts: string[];
    /** Sent back with a drag, so one made on top of somebody else's is refused. */
    version: string | null;
    /** On a phase header: its issues done, out of all of them (cancelled aside). */
    progress?: { done: number; total: number } | null;
    /** On an issue under a phase header: that header's key. */
    phase?: string | null;
}

/** The rows left to draw once the collapsed phases have folded their issues away. */
export function unfolded<T extends Pick<TimelineRow, 'phase'>>(rows: T[], collapsed: Set<string>): T[] {
    return rows.filter((row) => !row.phase || !collapsed.has(row.phase));
}

/** What a drag is changing: the whole bar, or one end of it. */
type DragMode = 'move' | 'start' | 'end';

interface Drag {
    key: string;
    mode: DragMode;
    originX: number;
    delta: number;
}

/** The dates to save for a row after moving `mode` by `delta` days. */
export function rescheduled(
    row: Pick<TimelineRow, 'kind' | 'anchor' | 'start' | 'end' | 'start_on' | 'due_on'>,
    mode: DragMode,
    delta: number,
): { start_on: string | null; due_on: string | null } {
    if (row.kind === 'milestone') {
        // One date: move that one, and leave the missing one missing.
        return row.anchor === 'due'
            ? { start_on: row.start_on, due_on: addDays(row.end, delta) }
            : { start_on: addDays(row.start, delta), due_on: row.due_on };
    }

    const start = mode === 'end' ? row.start : addDays(row.start, delta);
    const end = mode === 'start' ? row.end : addDays(row.end, delta);

    // Dragging one end past the other stops at the other: a bar is at least a day.
    if (mode === 'start' && start > end) return { start_on: end, due_on: end };
    if (mode === 'end' && end < start) return { start_on: start, due_on: start };

    return { start_on: start, due_on: end };
}

export function addDays(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

export interface TimelineAxis {
    from: string;
    to: string;
    interval: string;
    ticks: string[];
    today: string;
}

const ROW = 30;
const HEADER = 26;

/**
 * How much room a day gets, per axis scale.
 *
 * Chosen so the gap between two labels is always a little wider than a label: at the
 * week scale that is seven days, at the month scale about thirty. A scale where the
 * dates overlap each other is a scale with no axis at all.
 */
const PX_PER_DAY: Record<string, number> = { day: 26, week: 7, month: 2.2 };

function dayNumber(iso: string, from: string): number {
    // UTC on both sides: the axis is whole days, and parsing in local time puts a
    // bar an hour out twice a year for no reason anybody could debug.
    return Math.round(
        (Date.parse(`${iso}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000,
    );
}

function tickLabel(iso: string, interval: string): string {
    const date = new Date(`${iso}T00:00:00Z`);

    return date.toLocaleDateString(undefined, {
        timeZone: 'UTC',
        day: interval === 'month' ? undefined : 'numeric',
        month: 'short',
        year: interval === 'month' ? '2-digit' : undefined,
    });
}

export function TimelineChart({
    rows: allRows,
    axis,
    editable = false,
    onReschedule,
    onPlace,
    onLink,
    onUnlink,
    allLinks = true,
}: {
    rows: TimelineRow[];
    axis: TimelineAxis;
    /** Staff can drag; everybody else sees the same chart, still. */
    editable?: boolean;
    onReschedule?: (row: TimelineRow, dates: { start_on: string | null; due_on: string | null }) => void;
    /** An undated issue dropped onto a day. */
    onPlace?: (key: string, version: string, day: string) => void;
    /** A line drawn from one bar to another: the first blocks the second. */
    onLink?: (blocker: string, blocked: string) => void;
    onUnlink?: (blocker: string, blocked: string) => void;
    /** Every dependency, rather than only the ones running late. */
    allLinks?: boolean;
}) {
    const [drag, setDrag] = useState<Drag | null>(null);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    // A link being drawn: from which bar, and where the pointer is now (chart pixels).
    const [linking, setLinking] = useState<{ from: string; x: number; y: number } | null>(null);
    const [selected, setSelected] = useState<{ blocker: string; blocked: string } | null>(null);
    const svg = useRef<SVGSVGElement>(null);
    const rows = unfolded(allRows, collapsed);

    function toggle(key: string) {
        setCollapsed((current) => {
            const next = new Set(current);
            if (next.has(key)) next.delete(key);
            else next.add(key);

            return next;
        });
    }
    // Where a dragged bar was dropped, shown until the server's answer replaces it —
    // so a bar does not jump back for the length of a request and then forward again.
    const [pending, setPending] = useState<Record<string, { start: string; end: string }>>({});
    const scroller = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setPending({});
        setSelected(null);
    }, [allRows]);

    if (allRows.length === 0 && !onPlace) {
        return (
            <p className="rounded-xl border border-border px-4 py-8 text-center text-sm text-ink-subtle">
                Nothing with dates in this range.
            </p>
        );
    }

    const perDay = PX_PER_DAY[axis.interval] ?? 6;
    const canDrag = editable && Boolean(onReschedule);

    /** The row as it should be drawn right now: mid-drag, just dropped, or as loaded. */
    function shown(row: TimelineRow): TimelineRow {
        if (drag?.key === row.key) {
            const dates = rescheduled(row, drag.mode, drag.delta);
            const start = dates.start_on ?? dates.due_on ?? row.start;
            const end = dates.due_on ?? dates.start_on ?? row.end;

            return { ...row, start, end };
        }

        return pending[row.key] ? { ...row, ...pending[row.key] } : row;
    }

    function begin(event: ReactPointerEvent, row: TimelineRow, mode: DragMode) {
        if (!canDrag || row.kind === 'rollup' || row.kind === 'phase' || event.button !== 0) return;

        event.preventDefault();
        event.stopPropagation();
        (event.currentTarget as Element).closest('svg')?.setPointerCapture(event.pointerId);
        setDrag({ key: row.key, mode, originX: event.clientX, delta: 0 });
    }

    /** A point on the page, in the chart's own pixels. */
    function local(event: { clientX: number; clientY: number }) {
        const box = svg.current?.getBoundingClientRect();

        return { x: event.clientX - (box?.left ?? 0), y: event.clientY - (box?.top ?? 0) };
    }

    function beginLink(event: ReactPointerEvent, row: TimelineRow) {
        if (!onLink || event.button !== 0) return;

        event.preventDefault();
        event.stopPropagation();
        svg.current?.setPointerCapture(event.pointerId);
        setLinking({ from: row.key, ...local(event) });
    }

    function move(event: ReactPointerEvent) {
        if (linking) {
            setLinking({ ...linking, ...local(event) });
            return;
        }

        if (!drag) return;

        // Whole days: a plan is in days, and half a day dragged is a day nobody chose.
        const delta = Math.round((event.clientX - drag.originX) / perDay);

        if (delta !== drag.delta) setDrag({ ...drag, delta });
    }

    function end() {
        if (linking) {
            // Whichever issue row the pointer let go over. A phase header is not work.
            const target = rows[Math.floor((linking.y - HEADER) / ROW)];
            setLinking(null);

            if (target && target.kind !== 'phase' && target.key !== linking.from) {
                onLink?.(linking.from, target.key);
            }

            return;
        }

        if (!drag) return;

        const row = rows.find((r) => r.key === drag.key);
        setDrag(null);

        if (!row || drag.delta === 0) return;

        commit(row, drag.mode, drag.delta);
    }

    function commit(row: TimelineRow, mode: DragMode, delta: number) {
        const dates = rescheduled(row, mode, delta);

        setPending((current) => ({
            ...current,
            [row.key]: {
                start: dates.start_on ?? dates.due_on ?? row.start,
                end: dates.due_on ?? dates.start_on ?? row.end,
            },
        }));

        onReschedule?.(row, dates);
    }

    /** Arrow keys move a focused bar a day; with Shift they move its due date. */
    function onKey(event: KeyboardEvent, row: TimelineRow) {
        if (!canDrag || row.kind === 'rollup' || row.kind === 'phase') return;
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;

        event.preventDefault();
        commit(row, event.shiftKey && row.kind === 'bar' ? 'end' : 'move', event.key === 'ArrowRight' ? 1 : -1);
    }

    /** The day under a point in the chart, for dropping an undated issue onto. */
    function dayAt(clientX: number): string {
        const box = scroller.current?.getBoundingClientRect();
        const offset = clientX - (box?.left ?? 0) + (scroller.current?.scrollLeft ?? 0);
        const day = Math.max(0, Math.min(Math.floor(offset / perDay), dayNumber(axis.to, axis.from)));

        return addDays(axis.from, day);
    }
    const days = dayNumber(axis.to, axis.from) + 1;
    const width = Math.max(Math.round(days * perDay), 320);
    // Room for at least a few rows, so an empty chart is still somewhere to drop.
    const height = HEADER + Math.max(rows.length, onPlace ? 4 : 0) * ROW;

    // Clamped rather than dropped: a bar running out of the window still tells you
    // the work reaches the edge, which is the thing worth knowing about it.
    const x = (iso: string) => Math.min(Math.max(dayNumber(iso, axis.from), 0), days) * perDay;
    const xEnd = (iso: string) =>
        Math.min(Math.max(dayNumber(iso, axis.from) + 1, 0), days) * perDay;

    const rowY = (index: number) => HEADER + index * ROW;
    const indexOf = new Map(rows.map((row, i) => [row.key, i]));

    const todayX = x(axis.today);
    const todayInRange =
        dayNumber(axis.today, axis.from) >= 0 && dayNumber(axis.today, axis.from) <= days;

    return (
        <div className="space-y-2">
            {selected && (
                <div className="flex items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink">
                    <span>
                        <span className="font-mono text-xs">{selected.blocker}</span> blocks{' '}
                        <span className="font-mono text-xs">{selected.blocked}</span>
                    </span>
                    <button
                        type="button"
                        onClick={() => {
                            onUnlink?.(selected.blocker, selected.blocked);
                            setSelected(null);
                        }}
                        className="rounded-md border border-border px-2 py-0.5 text-xs text-danger transition hover:bg-danger-soft"
                    >
                        Remove link
                    </button>
                    <button
                        type="button"
                        onClick={() => setSelected(null)}
                        className="ml-auto text-xs text-ink-subtle hover:text-ink"
                    >
                        Close
                    </button>
                </div>
            )}
        <div className="flex overflow-hidden rounded-xl border border-border">
            <div className="w-56 shrink-0 border-r border-border sm:w-72">
                <div style={{ height: HEADER }} className="border-b border-border" />
                {rows.map((row) =>
                    row.kind === 'phase' ? (
                        <PhaseLabel
                            key={row.key}
                            row={row}
                            collapsed={collapsed.has(row.key)}
                            onToggle={() => toggle(row.key)}
                        />
                    ) : (
                    <div
                        key={row.key}
                        style={{ height: ROW, paddingLeft: 8 + row.depth * 14 + (row.phase ? 10 : 0) }}
                        className="flex items-center gap-2 overflow-hidden pr-2"
                        title={`${row.title} — ${row.project} · ${row.status}`}
                    >
                        <Link
                            href={`/issues/${row.key}`}
                            className="shrink-0 font-mono text-[11px] text-accent"
                        >
                            {row.key}
                        </Link>
                        <span
                            className={`truncate text-xs ${row.overdue ? 'text-danger' : 'text-ink'}`}
                        >
                            {row.title}
                        </span>
                        {row.blocked_by.length > 0 && (
                            <span
                                title={`Blocked by ${row.blocked_by.join(', ')}`}
                                className="shrink-0 text-[10px] text-ink-subtle"
                            >
                                ⛔{row.blocked_by.length}
                            </span>
                        )}
                    </div>
                    ),
                )}
            </div>

            <div
                ref={scroller}
                className="min-w-0 flex-1 overflow-x-auto"
                onDragOver={(event) => {
                    if (onPlace && event.dataTransfer.types.includes('application/x-buggie-issue')) {
                        event.preventDefault();
                        event.dataTransfer.dropEffect = 'move';
                    }
                }}
                onDrop={(event) => {
                    const raw = event.dataTransfer.getData('application/x-buggie-issue');
                    if (!onPlace || !raw) return;

                    event.preventDefault();
                    const { key, version } = JSON.parse(raw) as { key: string; version: string };
                    onPlace(key, version, dayAt(event.clientX));
                }}
            >
                <svg
                    ref={svg}
                    width={width}
                    height={height}
                    role={canDrag ? 'application' : 'img'}
                    aria-label={`${rows.length} issues from ${axis.from} to ${axis.to}${canDrag ? '. Drag a bar to move it, or its ends to change its dates; with a bar focused, the arrow keys move it a day and Shift moves its due date.' : ''}`}
                    className={`block ${drag ? 'cursor-grabbing select-none' : ''}`}
                    onPointerMove={move}
                    onPointerUp={end}
                    onPointerCancel={() => {
                        setDrag(null);
                        setLinking(null);
                    }}
                >
                    {/* A tint across the header rows, so the stages read as sections. */}
                    {rows.map((row, i) =>
                        row.kind === 'phase' ? (
                            <rect key={`band-${row.key}`} x={0} y={rowY(i)} width={width} height={ROW} className="fill-surface" />
                        ) : null,
                    )}

                    {axis.ticks.map((tick) => (
                        <g key={tick}>
                            <line
                                x1={x(tick)}
                                x2={x(tick)}
                                y1={HEADER}
                                y2={height}
                                strokeWidth={1}
                                className="stroke-border"
                            />
                            <text
                                x={x(tick) + 4}
                                y={HEADER - 9}
                                className="fill-ink-subtle text-[10px]"
                            >
                                {tickLabel(tick, axis.interval)}
                            </text>
                        </g>
                    ))}

                    <line
                        x1={0}
                        x2={width}
                        y1={HEADER}
                        y2={HEADER}
                        strokeWidth={1}
                        className="stroke-border"
                    />

                    {/* Today, so "late" is something you can see rather than work out. */}
                    {todayInRange && (
                        <line
                            x1={todayX}
                            x2={todayX}
                            y1={0}
                            y2={height}
                            strokeWidth={1}
                            strokeDasharray="3 3"
                            className="stroke-accent"
                        />
                    )}

                    {rows.map((row, i) => (
                        <Bar
                            key={row.key}
                            row={shown(row)}
                            y={rowY(i)}
                            x={x}
                            xEnd={xEnd}
                            draggable={canDrag && row.kind !== 'rollup' && row.kind !== 'phase'}
                            dragging={drag?.key === row.key}
                            onBegin={(event, mode) => begin(event, row, mode)}
                            onKey={(event) => onKey(event, row)}
                        />
                    ))}

                    {/*
                        Every dependency between two rows on the chart, with the ones
                        in trouble — a blocker ending after the work waiting on it has
                        started — in red. With allLinks off, only those.
                    */}
                    {rows.flatMap((row, i) =>
                        row.blocked_by.map((blockerKey) => {
                            const j = indexOf.get(blockerKey);
                            const late = row.conflicts.includes(blockerKey);

                            if (j === undefined || (!allLinks && !late)) return null;

                            return (
                                <Connector
                                    key={`${row.key}-${blockerKey}`}
                                    fromX={xEnd(shown(rows[j]).end)}
                                    fromY={rowY(j) + ROW / 2}
                                    toX={x(shown(row).start)}
                                    toY={rowY(i) + ROW / 2}
                                    late={late}
                                    label={`${blockerKey} blocks ${row.key}${late ? ', and ends after it starts' : ''}`}
                                    selected={selected?.blocker === blockerKey && selected.blocked === row.key}
                                    onSelect={onUnlink ? () => setSelected({ blocker: blockerKey, blocked: row.key }) : undefined}
                                />
                            );
                        }),
                    )}

                    {/* The handles links are drawn from, just past the end of each bar. */}
                    {onLink &&
                        rows.map((row, i) =>
                            row.kind === 'phase' || row.kind === 'rollup' ? null : (
                                <circle
                                    key={`link-${row.key}`}
                                    cx={xEnd(shown(row).end) + 9}
                                    cy={rowY(i) + ROW / 2}
                                    r={3.5}
                                    className={`cursor-crosshair fill-canvas stroke-ink-subtle hover:stroke-accent ${linking?.from === row.key ? 'stroke-accent' : ''}`}
                                    strokeWidth={1.5}
                                    style={{ touchAction: 'none' }}
                                    onPointerDown={(event) => beginLink(event, row)}
                                >
                                    <title>{`Drag onto another issue: ${row.key} blocks it`}</title>
                                </circle>
                            ),
                        )}

                    {linking && indexOf.has(linking.from) && (
                        <line
                            x1={xEnd(shown(rows[indexOf.get(linking.from)!]).end) + 9}
                            y1={rowY(indexOf.get(linking.from)!) + ROW / 2}
                            x2={linking.x}
                            y2={linking.y}
                            strokeWidth={1.5}
                            strokeDasharray="4 3"
                            className="pointer-events-none stroke-accent"
                        />
                    )}
                </svg>
            </div>
        </div>
        </div>
    );
}

function Bar({
    row,
    y,
    x,
    xEnd,
    draggable = false,
    dragging = false,
    onBegin,
    onKey,
}: {
    row: TimelineRow;
    y: number;
    x: (iso: string) => number;
    xEnd: (iso: string) => number;
    draggable?: boolean;
    dragging?: boolean;
    onBegin?: (event: ReactPointerEvent, mode: DragMode) => void;
    onKey?: (event: KeyboardEvent) => void;
}) {
    const tone = row.overdue ? 'fill-danger' : row.open ? 'fill-accent' : 'fill-success';
    const label = `${row.key} ${row.start}${row.start === row.end ? '' : ` to ${row.end}`}`;
    const grab = draggable ? { onPointerDown: (e: ReactPointerEvent) => onBegin?.(e, 'move') } : {};
    const focus = draggable
        ? {
              tabIndex: 0,
              role: 'button',
              'aria-label': `${label}. Arrow keys move it a day.`,
              onKeyDown: onKey,
              className: 'outline-none focus-visible:[&>*:not(title)]:stroke-ink focus-visible:[&>*:not(title)]:stroke-2',
          }
        : {};

    // The dates being chosen, above the bar while it moves: the whole point of
    // dragging is to land on a day, and a bar alone does not say which.
    const readout = dragging && (
        <text x={x(row.start)} y={y + 6} className="fill-ink text-[10px] font-medium">
            {row.start === row.end ? row.start : `${row.start} → ${row.end}`}
        </text>
    );

    if (row.kind === 'milestone') {
        // One date only. A diamond claims a moment and nothing either side of it,
        // which is exactly as much as is known.
        const centre = (x(row.start) + xEnd(row.end)) / 2;
        const mid = y + ROW / 2;
        const r = 5;

        return (
            <g {...focus}>
                <title>{`${label} · ${row.anchor === 'due' ? 'due date only' : 'start date only'}`}</title>
                {readout}
                <polygon
                    points={`${centre},${mid - r} ${centre + r},${mid} ${centre},${mid + r} ${centre - r},${mid}`}
                    className={`${tone} ${draggable ? 'cursor-grab' : ''}`}
                    style={draggable ? { touchAction: 'none' } : undefined}
                    {...grab}
                />
            </g>
        );
    }

    const left = x(row.start);
    const right = Math.max(xEnd(row.end), left + 3);

    if (row.kind === 'phase') {
        // The stage's span, with how much of it is done filled in from the left. Not
        // a date anybody set: a phase runs from its first issue to its last.
        const progress = row.progress && row.progress.total > 0 ? row.progress.done / row.progress.total : 0;

        return (
            <g>
                <title>{`${row.title} · ${row.start} to ${row.end}${row.progress ? ` · ${row.progress.done} of ${row.progress.total} done` : ''}`}</title>
                <rect x={left} y={y + ROW / 2 - 4} width={right - left} height={8} rx={4} className="fill-ink-muted/30" />
                {progress > 0 && (
                    <rect
                        x={left}
                        y={y + ROW / 2 - 4}
                        width={(right - left) * progress}
                        height={8}
                        rx={4}
                        className="fill-success"
                    />
                )}
            </g>
        );
    }

    if (row.kind === 'rollup') {
        // A bracket, not a bar: this parent has no dates of its own and is standing
        // in for the work underneath it. Drawing it solid would read as a commitment
        // nobody made.
        const top = y + ROW / 2 - 3;

        return (
            <g>
                <title>{`${label} · spans its subtasks`}</title>
                <path
                    d={`M ${left} ${top + 6} V ${top} H ${right} V ${top + 6}`}
                    fill="none"
                    strokeWidth={2}
                    className={row.overdue ? 'stroke-danger' : 'stroke-ink-muted'}
                />
            </g>
        );
    }

    return (
        <g {...focus}>
            <title>{label}</title>
            {readout}
            <rect
                x={left}
                y={y + ROW / 2 - 6}
                width={right - left}
                height={12}
                rx={3}
                className={`${tone} ${draggable ? 'cursor-grab' : ''} ${dragging ? 'opacity-80' : ''}`}
                style={draggable ? { touchAction: 'none' } : undefined}
                {...grab}
            />
            {/* The ends, a little wider than they look, so they can be caught. */}
            {draggable &&
                (['start', 'end'] as const).map((mode) => (
                    <rect
                        key={mode}
                        x={(mode === 'start' ? left : right) - 4}
                        y={y + ROW / 2 - 8}
                        width={8}
                        height={16}
                        fill="transparent"
                        className="cursor-ew-resize"
                        style={{ touchAction: 'none' }}
                        onPointerDown={(e) => onBegin?.(e, mode)}
                    />
                ))}
        </g>
    );
}

/** A phase's name, how far along it is, and the control that folds it away. */
function PhaseLabel({
    row,
    collapsed,
    onToggle,
}: {
    row: TimelineRow;
    collapsed: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={!collapsed}
            style={{ height: ROW }}
            className="flex w-full items-center gap-1.5 overflow-hidden bg-surface pr-2 pl-2 text-left"
            title={`${row.title} — ${row.children} on the chart`}
        >
            <span className={`shrink-0 text-[10px] text-ink-subtle transition ${collapsed ? '' : 'rotate-90'}`}>▶</span>
            <span className="truncate text-xs font-semibold text-ink">{row.title}</span>
            {row.progress && row.progress.total > 0 && (
                <span className="ml-auto shrink-0 text-[10px] text-ink-subtle">
                    {row.progress.done}/{row.progress.total} done
                </span>
            )}
        </button>
    );
}

/** An elbow from the blocker's end to the blocked issue's start. */
function Connector({
    fromX,
    fromY,
    toX,
    toY,
    late,
    label,
    selected = false,
    onSelect,
}: {
    fromX: number;
    fromY: number;
    toX: number;
    toY: number;
    late: boolean;
    label: string;
    selected?: boolean;
    onSelect?: () => void;
}) {
    // Out, down, and back in. A straight diagonal across a dense chart is impossible
    // to follow to its other end.
    const elbow = fromX + 8;
    const d = `M ${fromX} ${fromY} H ${elbow} V ${toY} H ${toX}`;

    return (
        <g>
            <title>{label}</title>
            <path
                d={d}
                fill="none"
                strokeWidth={selected ? 2 : 1}
                strokeDasharray={late ? '2 2' : undefined}
                className={late ? 'stroke-danger' : selected ? 'stroke-accent' : 'stroke-ink-subtle'}
            />
            {/* A wider invisible line over it, so a one-pixel path can be clicked. */}
            {onSelect && (
                <path
                    d={d}
                    fill="none"
                    stroke="transparent"
                    strokeWidth={8}
                    className="cursor-pointer"
                    onClick={onSelect}
                />
            )}
        </g>
    );
}
