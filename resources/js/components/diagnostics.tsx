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
    duration?: number;
}

export interface DiagnosticsData {
    occurrence_count: number;
    first_seen_at: string | null;
    last_seen_at: string | null;
    environment: Record<string, string> | null;
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

function when(value: string | null): string | undefined {
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
export function Diagnostics({ data }: { data: DiagnosticsData }) {
    const [expanded, setExpanded] = useState(false);

    const environment = data.environment ?? {};
    const console = data.console ?? [];
    const network = data.network ?? [];

    // Native reports describe themselves differently from browser ones, and the card
    // should not look half-empty for either.
    const native = environment.platform === 'ios' || environment.platform === 'android';

    return (
        <section className="py-4">
            <h2 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                Diagnostics
            </h2>

            {data.occurrence_count > 1 && (
                <p className="mt-2 flex items-center gap-1.5 text-sm text-ink">
                    <Repeat className="size-3.5 shrink-0 text-ink-subtle" />
                    Reported {data.occurrence_count} times
                </p>
            )}

            {data.error?.message && (
                <pre className="mt-3 overflow-x-auto rounded-lg bg-surface p-2.5 font-mono text-[11px] text-danger">
                    {data.error.message}
                </pre>
            )}

            <dl className="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[11px]">
                {native ? (
                    <>
                        <Fact icon={Smartphone} label="Device" value={environment.device} />
                        <Fact icon={Monitor} label="OS" value={environment.os} />
                        <Fact
                            icon={Tag}
                            label="App"
                            value={
                                environment.app_version
                                    ? `${environment.app_version}${environment.app_build ? ` (${environment.app_build})` : ''}`
                                    : undefined
                            }
                        />
                    </>
                ) : (
                    <>
                        <Fact icon={Globe} label="Page" value={environment.url} />
                        <Fact icon={Monitor} label="Browser" value={environment.user_agent} />
                    </>
                )}
                <Fact icon={Tag} label="Release" value={environment.release} />
                <Fact icon={Clock} label="First seen" value={when(data.first_seen_at)} />
                <Fact icon={Clock} label="Last seen" value={when(data.last_seen_at)} />
            </dl>

            {(console.length > 0 || network.length > 0) && (
                <>
                    <button
                        type="button"
                        onClick={() => setExpanded((e) => !e)}
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
                                <table className="w-full font-mono text-[10px]">
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
                                                <td className="max-w-0 truncate py-0.5" title={call.url}>
                                                    {call.url}
                                                </td>
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
