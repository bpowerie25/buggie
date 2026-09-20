import { useId } from 'react';

/**
 * Small SVG charts, drawn by hand.
 *
 * A charting library is 70–150KB for what amounts to four shapes, and every one of
 * them brings its own opinions about theming that then have to be fought. These read
 * the same CSS custom properties as everything else, so dark mode is free.
 *
 * They are deliberately not general-purpose. When a fifth kind of chart is needed,
 * write a fifth component rather than growing options on these.
 */

export interface Point {
    bucket: string;
    opened: number;
    closed: number;
    open: number;
}

/** Bars go up from a baseline; ticks are thinned so labels never collide. */
export function ThroughputChart({
    points,
    interval,
}: {
    points: Point[];
    interval: string;
}) {
    const id = useId();

    if (points.length === 0) return null;

    const max = Math.max(...points.flatMap((p) => [p.opened, p.closed]), 1);
    const width = 100;
    const height = 32;
    const slot = width / points.length;
    const barWidth = Math.max(slot * 0.32, 0.4);

    // At most eight labels, whatever the range, so a 90-day chart is readable.
    const every = Math.ceil(points.length / 8);

    return (
        <div>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                className="h-40 w-full"
                role="img"
                aria-label={`Issues opened and closed per ${interval}`}
            >
                {points.map((point, i) => {
                    const x = i * slot + slot / 2;
                    const openedH = (point.opened / max) * (height - 2);
                    const closedH = (point.closed / max) * (height - 2);

                    return (
                        <g key={point.bucket}>
                            <rect
                                x={x - barWidth - 0.15}
                                y={height - openedH}
                                width={barWidth}
                                height={openedH}
                                className="fill-accent"
                            />
                            <rect
                                x={x + 0.15}
                                y={height - closedH}
                                width={barWidth}
                                height={closedH}
                                className="fill-success"
                            />
                        </g>
                    );
                })}
            </svg>

            <div className="mt-1 flex justify-between text-[10px] text-ink-subtle">
                {points.map((point, i) => (
                    <span key={`${id}-${point.bucket}`} className="flex-1 text-center">
                        {i % every === 0 ? shortDate(point.bucket) : ''}
                    </span>
                ))}
            </div>

            <div className="mt-2 flex gap-4 text-xs text-ink-muted">
                <Key className="bg-accent" label="Opened" />
                <Key className="bg-success" label="Closed" />
            </div>
        </div>
    );
}

/** The backlog over time. One line, because one number is the question. */
export function BacklogChart({ points }: { points: Point[] }) {
    if (points.length < 2) return null;

    const max = Math.max(...points.map((p) => p.open), 1);
    const width = 100;
    const height = 24;

    const coords = points.map((point, i) => {
        const x = (i / (points.length - 1)) * width;
        const y = height - (point.open / max) * (height - 1);

        return `${x.toFixed(2)},${y.toFixed(2)}`;
    });

    return (
        <div>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                className="h-28 w-full"
                role="img"
                aria-label="Open issues over time"
            >
                {/* Filled underneath, so the shape reads at a glance rather than
                    needing the axis. */}
                <polygon
                    points={`0,${height} ${coords.join(' ')} ${width},${height}`}
                    className="fill-accent/15"
                />
                <polyline
                    points={coords.join(' ')}
                    fill="none"
                    strokeWidth={0.5}
                    vectorEffect="non-scaling-stroke"
                    className="stroke-accent"
                />
            </svg>

            <div className="mt-1 flex justify-between text-[10px] text-ink-subtle">
                <span>{shortDate(points[0].bucket)}</span>
                <span>
                    {points[points.length - 1].open} open · peak {max}
                </span>
                <span>{shortDate(points[points.length - 1].bucket)}</span>
            </div>
        </div>
    );
}

/** A ranked list with a proportion bar. Honest about being a table. */
export function BarList({
    rows,
    empty = 'Nothing yet.',
}: {
    rows: { name: string; count: number }[];
    empty?: string;
}) {
    if (rows.length === 0) {
        return <p className="py-4 text-sm text-ink-subtle">{empty}</p>;
    }

    const max = Math.max(...rows.map((row) => row.count), 1);

    return (
        <ul className="space-y-2">
            {rows.map((row) => (
                <li key={row.name}>
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate text-ink">{row.name}</span>
                        <span className="shrink-0 tabular-nums text-ink-muted">{row.count}</span>
                    </div>
                    <div className="mt-1 h-1.5 rounded-full bg-border">
                        <div
                            className="h-1.5 rounded-full bg-accent"
                            style={{ width: `${(row.count / max) * 100}%` }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function Key({ className, label }: { className: string; label: string }) {
    return (
        <span className="flex items-center gap-1.5">
            <span className={`size-2 rounded-sm ${className}`} />
            {label}
        </span>
    );
}

/** "3 Sep" — the year is on the range picker above and does not need repeating. */
function shortDate(iso: string): string {
    const date = new Date(`${iso}T00:00:00`);

    return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
