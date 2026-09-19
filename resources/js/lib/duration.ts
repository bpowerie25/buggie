/**
 * Durations, in the browser.
 *
 * A deliberate second implementation of App\Support\Time\Duration, and the only
 * reason it exists is the preview under the input: somebody typing "90" is shown
 * "= 1h 30m" before they save, which is what makes a bare number unambiguous.
 *
 * The server remains the authority — it re-parses everything and rejects what it
 * does not like. If the two ever disagree the preview is wrong, never the stored
 * value. The rules are kept in step by tests on both sides using the same cases.
 */

export const MAX_MINUTES = 24 * 60;

export function parseDuration(input: string): number | null {
    const text = input.trim().toLowerCase();

    if (text === '') return null;

    const clock = text.match(/^(\d+):([0-5]?\d)$/);
    if (clock) return guard(Number(clock[1]) * 60 + Number(clock[2]));

    if (/^\d+$/.test(text)) return guard(Number(text));

    const parts = text.match(/^(?:(\d+(?:[.,]\d+)?)\s*h)?\s*(?:(\d+(?:[.,]\d+)?)\s*m?)?$/);
    if (!parts) return null;

    const hours = parts[1] ? Number(parts[1].replace(',', '.')) : 0;
    const minutes = parts[2] ? Number(parts[2].replace(',', '.')) : 0;

    if (hours === 0 && minutes === 0) return null;

    return guard(Math.round(hours * 60 + minutes));
}

export function formatDuration(minutes: number | null): string {
    if (minutes === null) return '—';
    if (minutes === 0) return '0m';

    const sign = minutes < 0 ? '-' : '';
    const total = Math.abs(minutes);
    const hours = Math.floor(total / 60);
    const rest = total % 60;

    if (hours === 0) return `${sign}${rest}m`;

    return rest === 0 ? `${sign}${hours}h` : `${sign}${hours}h ${rest}m`;
}

function guard(minutes: number): number | null {
    if (!Number.isFinite(minutes) || minutes <= 0 || minutes > MAX_MINUTES) return null;

    return minutes;
}
