import { Link } from '@inertiajs/react';

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
    kind: 'bar' | 'milestone' | 'rollup';
    anchor: 'start' | 'due';
    open: boolean;
    overdue: boolean;
    children: number;
    estimate: string | null;
    blocked_by: string[];
    conflicts: string[];
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
    rows,
    axis,
}: {
    rows: TimelineRow[];
    axis: TimelineAxis;
}) {
    if (rows.length === 0) {
        return (
            <p className="rounded-xl border border-border px-4 py-8 text-center text-sm text-ink-subtle">
                Nothing with dates in this range.
            </p>
        );
    }

    const perDay = PX_PER_DAY[axis.interval] ?? 6;
    const days = dayNumber(axis.to, axis.from) + 1;
    const width = Math.max(Math.round(days * perDay), 320);
    const height = HEADER + rows.length * ROW;

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
        <div className="flex overflow-hidden rounded-xl border border-border">
            <div className="w-56 shrink-0 border-r border-border sm:w-72">
                <div style={{ height: HEADER }} className="border-b border-border" />
                {rows.map((row) => (
                    <div
                        key={row.key}
                        style={{ height: ROW, paddingLeft: 8 + row.depth * 14 }}
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
                ))}
            </div>

            <div className="min-w-0 flex-1 overflow-x-auto">
                <svg
                    width={width}
                    height={height}
                    role="img"
                    aria-label={`${rows.length} issues from ${axis.from} to ${axis.to}`}
                    className="block"
                >
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
                        <Bar key={row.key} row={row} y={rowY(i)} x={x} xEnd={xEnd} />
                    ))}

                    {/*
                        Only the dependencies that are in trouble get a line. A
                        connector for every `blocks` relation is a ball of string, and
                        most of them repeat what the ordering already shows.
                    */}
                    {rows.flatMap((row, i) =>
                        row.conflicts.map((blockerKey) => {
                            const j = indexOf.get(blockerKey);

                            if (j === undefined) return null;

                            return (
                                <Connector
                                    key={`${row.key}-${blockerKey}`}
                                    fromX={xEnd(rows[j].end)}
                                    fromY={rowY(j) + ROW / 2}
                                    toX={x(row.start)}
                                    toY={rowY(i) + ROW / 2}
                                />
                            );
                        }),
                    )}
                </svg>
            </div>
        </div>
    );
}

function Bar({
    row,
    y,
    x,
    xEnd,
}: {
    row: TimelineRow;
    y: number;
    x: (iso: string) => number;
    xEnd: (iso: string) => number;
}) {
    const tone = row.overdue ? 'fill-danger' : row.open ? 'fill-accent' : 'fill-success';
    const label = `${row.key} ${row.start}${row.start === row.end ? '' : ` to ${row.end}`}`;

    if (row.kind === 'milestone') {
        // One date only. A diamond claims a moment and nothing either side of it,
        // which is exactly as much as is known.
        const centre = (x(row.start) + xEnd(row.end)) / 2;
        const mid = y + ROW / 2;
        const r = 5;

        return (
            <g>
                <title>{`${label} · ${row.anchor === 'due' ? 'due date only' : 'start date only'}`}</title>
                <polygon
                    points={`${centre},${mid - r} ${centre + r},${mid} ${centre},${mid + r} ${centre - r},${mid}`}
                    className={tone}
                />
            </g>
        );
    }

    const left = x(row.start);
    const right = Math.max(xEnd(row.end), left + 3);

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
        <g>
            <title>{label}</title>
            <rect
                x={left}
                y={y + ROW / 2 - 6}
                width={right - left}
                height={12}
                rx={3}
                className={tone}
            />
        </g>
    );
}

/** An elbow from the blocker's end to the blocked issue's start. */
function Connector({
    fromX,
    fromY,
    toX,
    toY,
}: {
    fromX: number;
    fromY: number;
    toX: number;
    toY: number;
}) {
    // Out, down, and back in. A straight diagonal across a dense chart is impossible
    // to follow to its other end.
    const elbow = fromX + 8;

    return (
        <path
            d={`M ${fromX} ${fromY} H ${elbow} V ${toY} H ${toX}`}
            fill="none"
            strokeWidth={1}
            strokeDasharray="2 2"
            className="stroke-danger"
        />
    );
}
