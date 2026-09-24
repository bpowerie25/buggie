/** Formatting and thresholds for the workload screen. */

/** How full a week is: nothing, comfortable, near the limit, or over it. */
export function load(minutes: number, capacity: number | null): 'none' | 'ok' | 'near' | 'over' | 'unknown' {
    if (minutes === 0) return 'none';
    if (capacity === null || capacity === 0) return 'unknown';

    const ratio = minutes / capacity;

    return ratio > 1 ? 'over' : ratio >= 0.85 ? 'near' : 'ok';
}

export function hours(minutes: number): string {
    const h = minutes / 60;

    return `${h >= 10 || Number.isInteger(h) ? Math.round(h) : h.toFixed(1)}h`;
}
