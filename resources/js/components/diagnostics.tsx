import {
    AlertCircle,
    ChevronRight,
    Clock,
    Globe,
    Monitor,
    Repeat,
    Smartphone,
    Tag,
} from 'lucide-react';
import { User as UserIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

export interface ConsoleEntry {
    level: string;
    message: string;
    at?: number;
}

export interface NetworkEntry {
    method: string;
    url: string;
    status: number | string;
    /** Milliseconds. Absent on reports filed before durations were captured. */
    duration?: number;
}

export interface DiagnosticsData {
    /** Absent in the inbox, which shows the group size in its own words. */
    occurrence_count?: number;
    first_seen_at?: string | null;
    last_seen_at?: string | null;
    /**
     * Whatever the reporter's platform chose to send. Arbitrary JSON rather than
     * strings: a browser sends `pixel_ratio` as a number, and a native SDK sends a
     * nested identity object.
     */
    environment: Record<string, unknown> | null;
    console: ConsoleEntry[] | null;
    network: NetworkEntry[] | null;
    error: { message?: string; stack?: string } | null;
}

function Fact({ icon: Icon, label, value }: { icon: typeof Globe; label: string; value?: ReactNode }) {
    if (!value) return null;

    return (
        <>
            <dt className="flex items-center gap-1.5 text-ink-subtle">
                <Icon className="size-3 shrink-0" />
                {label}
            </dt>
            <dd className="min-w-0 truncate text-ink-muted" title={typeof value === 'string' ? value : undefined}>
                {value}
            </dd>
        </>
    );
}

function when(value: string | null | undefined): string | undefined {
    if (!value) return undefined;

    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * What the reporter's browser or device saw.
 *
 * This is the whole reason the widget exists, and until now it was visible only in
 * the triage inbox — so it vanished the moment somebody decided the bug was worth
 * fixing, which is precisely when you start needing it.
 */
export function Diagnostics({
    data,
    heading,
    reporter,
    expanded: controlledExpanded,
    onExpandedChange,
}: {
    data: DiagnosticsData;
    /** Omitted where the surrounding card already says what this is. */
    heading?: string;
    reporter?: string;
    /**
     * Expansion is controlled where the host owns it — the triage inbox toggles this
     * with Enter and collapses it when you move to the next report, so the state
     * cannot live in here.
     */
    expanded?: boolean;
    onExpandedChange?: (expanded: boolean) => void;
}) {
    const [uncontrolled, setUncontrolled] = useState(false);

    const expanded = controlledExpanded ?? uncontrolled;
    const toggle = () =>
        onExpandedChange ? onExpandedChange(!expanded) : setUncontrolled((e) => !e);

    const environment = data.environment ?? {};

    /** Read a fact as text, ignoring anything that is not a scalar. */
    const text = (key: string): string | undefined => {
        const value = environment[key];

        if (value === null || value === undefined || typeof value === 'object') return undefined;

        return String(value);
    };
    const console = data.console ?? [];
    const network = data.network ?? [];
    const showDuration = network.some((call) => call.duration !== undefined);

    // Native reports describe themselves differently from browser ones, and the card
    // should not look half-empty for either.
    const native = text('platform') === 'ios' || text('platform') === 'android';

    return (
        <section className={heading ? 'py-4' : ''}>
            {heading && (
                <h2 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                    {heading}
                </h2>
            )}

            {(data.occurrence_count ?? 1) > 1 && (
                <p className="mt-2 flex items-center gap-1.5 text-sm text-ink">
                    <Repeat className="size-3.5 shrink-0 text-ink-subtle" />
                    Reported {data.occurrence_count} times
                </p>
            )}

            {/*
                Wrapped rather than scrolled: this renders in the issue page's narrow
                sidebar as well as the inbox's wide panel, and a horizontal scrollbar
                in a narrow column hides the end of the message behind a gesture
                nobody makes.
            */}
            {data.error?.message && (
                <pre className="mt-3 rounded-lg bg-surface p-2.5 font-mono text-[11px] break-words whitespace-pre-wrap text-danger">
                    {data.error.message}
                </pre>
            )}

            <dl className="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[11px]">
                {native ? (
                    <>
                        <Fact icon={Smartphone} label="Device" value={text('device')} />
                        <Fact icon={Monitor} label="OS" value={text('os')} />
                        <Fact
                            icon={Tag}
                            label="App"
                            value={
                                text('app_version')
                                    ? `${text('app_version')}${text('app_build') ? ` (${text('app_build')})` : ''}`
                                    : undefined
                            }
                        />
                    </>
                ) : (
                    <>
                        <Fact icon={Globe} label="Page" value={text('url')} />
                        <Fact icon={Monitor} label="Browser" value={text('user_agent')} />
                    </>
                )}
                <Fact icon={Tag} label="Release" value={text('release')} />
                <Fact icon={UserIcon} label="Reporter" value={reporter} />
                <Fact icon={Clock} label="First seen" value={when(data.first_seen_at)} />
                <Fact icon={Clock} label="Last seen" value={when(data.last_seen_at)} />
            </dl>

            {(console.length > 0 || network.length > 0) && (
                <>
                    <button
                        type="button"
                        onClick={toggle}
                        aria-expanded={expanded}
                        className="mt-3 flex items-center gap-1 text-[11px] text-ink-muted hover:text-ink"
                    >
                        <ChevronRight
                            className={`size-3 transition-transform ${expanded ? 'rotate-90' : ''}`}
                        />
                        Console ({console.length}) and network ({network.length})
                    </button>

                    {expanded && (
                        <div className="mt-2 space-y-3">
                            {console.length > 0 && (
                                <pre className="max-h-40 overflow-auto rounded-lg bg-surface p-2.5 font-mono text-[10px] text-ink-muted">
                                    {console.map((entry) => `[${entry.level}] ${entry.message}`).join('\n')}
                                </pre>
                            )}

                            {network.length > 0 && (
                                // table-fixed, and the widths live in a colgroup.
                                // Under the default auto layout a cell with max-w-0
                                // collapses to nothing and `truncate` hides what is
                                // inside it, which is why the URL column had been
                                // blank since this screen was built — every request
                                // showed its method, status and duration and never
                                // said what it was to.
                                <table className="w-full table-fixed font-mono text-[10px]">
                                    <colgroup>
                                        <col className="w-12" />
                                        <col className="w-10" />
                                        <col />
                                        {showDuration && <col className="w-14" />}
                                    </colgroup>
                                    <tbody className="text-ink-muted">
                                        {network.map((call, i) => (
                                            <tr key={i}>
                                                <td className="py-0.5 pr-2">{call.method}</td>
                                                <td
                                                    className={`py-0.5 pr-2 ${
                                                        Number(call.status) >= 400 || call.status === 'failed'
                                                            ? 'text-danger'
                                                            : ''
                                                    }`}
                                                >
                                                    {call.status}
                                                </td>
                                                <td className="truncate py-0.5" title={call.url}>
                                                    {call.url}
                                                </td>
                                                {showDuration && (
                                                    <td className="py-0.5 pl-2 text-right">
                                                        {call.duration === undefined ? '' : `${call.duration}ms`}
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    )}
                </>
            )}

            {console.length === 0 && network.length === 0 && !data.error?.message && (
                <p className="mt-3 flex items-start gap-1.5 text-[11px] text-ink-subtle">
                    <AlertCircle className="mt-0.5 size-3 shrink-0" />
                    Filed by hand or by email, so there is no captured context.
                </p>
            )}
        </section>
    );
}
