import { router } from '@inertiajs/react';
import { Square, Timer, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * The clock, wherever you are.
 *
 * Deliberately in the header rather than on the issue being timed. A timer's only
 * real failure is forgetting it, and one you can see only by navigating back to what
 * you were doing is one you will not see.
 */
export function RunningTimer({
    timer,
}: {
    timer: {
        started_at: string;
        billable: boolean;
        issue: { key: string; title: string };
        forgotten: boolean;
    };
}) {
    const [now, setNow] = useState(() => Date.now());

    // Ticks once a second purely so the number moves. Nothing depends on it being
    // accurate — the elapsed time that gets logged is worked out by the server from
    // the moment it started.
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(id);
    }, []);

    const seconds = Math.max(0, Math.floor((now - Date.parse(timer.started_at)) / 1000));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    const elapsed =
        hours > 0
            ? `${hours}h ${String(minutes).padStart(2, '0')}m`
            : `${minutes}m ${String(seconds % 60).padStart(2, '0')}s`;

    return (
        <div
            className={`flex items-center gap-2 rounded-lg border px-2 py-1 text-xs ${
                timer.forgotten
                    ? 'border-danger/40 bg-danger-soft text-danger'
                    : 'border-border bg-raised text-ink-muted'
            }`}
        >
            <Timer className="size-3.5 shrink-0" />

            <span className="hidden sm:inline">
                <span className="font-mono">{timer.issue.key}</span>
            </span>

            <span className="font-mono tabular-nums text-ink">{elapsed}</span>

            {/* Said by the server, so a clock left running over a weekend is flagged
                even if this tab was never open. */}
            {timer.forgotten && <span className="hidden md:inline">left running?</span>}

            <button
                type="button"
                aria-label="Stop the timer and log it"
                title="Stop and log"
                onClick={() => router.post('/timer/stop', {}, { preserveScroll: true })}
                className="rounded p-1 transition hover:bg-surface hover:text-ink"
            >
                <Square className="size-3.5" />
            </button>

            <button
                type="button"
                aria-label="Discard the timer without logging"
                title="Discard without logging"
                onClick={() => {
                    if (!confirm('Throw this timer away without logging any time?')) return;

                    router.delete('/timer', { preserveScroll: true });
                }}
                className="rounded p-1 transition hover:bg-surface hover:text-danger"
            >
                <Trash2 className="size-3.5" />
            </button>
        </div>
    );
}
