import { dateWithAge } from '@/components/issue-bits';
import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Log {
    id: number;
    at: string;
    actor: string | null;
    event: string;
    type: string | null;
    label: string | null;
    changes: Record<string, unknown> | null;
    ip: string | null;
}

interface Filters {
    actor_id?: string;
    type?: string;
    event?: string;
    q?: string;
    from?: string;
    to?: string;
}

const control =
    'rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-ink transition hover:border-border-strong';

function Changes({ changes }: { changes: Record<string, unknown> }) {
    return (
        <dl className="mt-1 space-y-0.5 text-xs text-ink-muted">
            {Object.entries(changes).map(([key, value]) => {
                const pair = value as { from?: unknown; to?: unknown } | null;
                const isDiff = pair && typeof pair === 'object' && ('from' in pair || 'to' in pair);

                return (
                    <div key={key} className="flex gap-2">
                        <dt className="shrink-0 font-mono text-ink-subtle">{key}</dt>
                        <dd className="min-w-0 break-words">
                            {isDiff ? (
                                <>
                                    <span className="text-ink-subtle line-through">{String(pair.from ?? '—')}</span>
                                    {' → '}
                                    <span className="text-ink">{String(pair.to ?? '—')}</span>
                                </>
                            ) : (
                                <span className="text-ink">
                                    {typeof value === 'string' ? value : JSON.stringify(value)}
                                </span>
                            )}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

export default function AuditPage({
    logs,
    filters,
    actors,
    types,
}: {
    logs: { data: Log[]; next_page_url: string | null; prev_page_url: string | null };
    filters: Filters;
    actors: { id: number; name: string }[];
    types: string[];
}) {
    const [open, setOpen] = useState<number | null>(null);

    function apply(changes: Partial<Filters>) {
        const next = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== undefined && v !== ''),
        );
        router.get('/settings/audit', next, { preserveState: true });
    }

    return (
        <AppLayout title="Audit log">
            <Head title="Audit log" />

            <p className="max-w-2xl text-sm text-pretty text-ink-muted">
                Who changed what, and when. Entries cannot be edited or removed. Comment text and
                secrets are never copied in.
            </p>

            <div className="mt-4 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    defaultValue={filters.q ?? ''}
                    placeholder="Search person, thing or event"
                    onKeyDown={(e) => e.key === 'Enter' && apply({ q: e.currentTarget.value })}
                    className={`${control} w-64`}
                />
                <select value={filters.actor_id ?? ''} onChange={(e) => apply({ actor_id: e.target.value })} className={control}>
                    <option value="">Anyone</option>
                    {actors.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name}
                        </option>
                    ))}
                </select>
                <select value={filters.type ?? ''} onChange={(e) => apply({ type: e.target.value })} className={control}>
                    <option value="">Everything</option>
                    {types.map((t) => (
                        <option key={t} value={t}>
                            {t.replace(/_/g, ' ')}
                        </option>
                    ))}
                </select>
                <input type="date" aria-label="From" value={filters.from ?? ''} onChange={(e) => apply({ from: e.target.value })} className={control} />
                <span className="text-sm text-ink-subtle">to</span>
                <input type="date" aria-label="To" value={filters.to ?? ''} onChange={(e) => apply({ to: e.target.value })} className={control} />
                {Object.values(filters).some(Boolean) && (
                    <Link href="/settings/audit" className="text-xs text-ink-subtle hover:text-ink">
                        Clear
                    </Link>
                )}
            </div>

            <div className="mt-4 overflow-x-auto rounded-xl border border-border bg-raised">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-ink-subtle">
                            <th className="px-4 py-2.5 font-medium">When</th>
                            <th className="px-3 py-2.5 font-medium">Who</th>
                            <th className="px-3 py-2.5 font-medium">What</th>
                            <th className="px-3 py-2.5 font-medium">On</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {logs.data.length === 0 && (
                            <tr>
                                <td colSpan={4} className="px-4 py-8 text-center text-ink-subtle">
                                    Nothing recorded for these filters.
                                </td>
                            </tr>
                        )}
                        {logs.data.map((log) => (
                            <tr
                                key={log.id}
                                className={`align-top ${log.changes ? 'cursor-pointer hover:bg-surface' : ''}`}
                                onClick={() => log.changes && setOpen(open === log.id ? null : log.id)}
                            >
                                <td className="px-4 py-2 whitespace-nowrap text-xs text-ink-muted" title={log.at}>
                                    {dateWithAge(log.at)}
                                </td>
                                <td className="px-3 py-2 text-ink">{log.actor ?? <span className="text-ink-subtle">System</span>}</td>
                                <td className="px-3 py-2 font-mono text-xs text-ink">{log.event}</td>
                                <td className="px-3 py-2">
                                    <span className="text-ink">{log.label ?? '—'}</span>
                                    {log.ip && <span className="ml-2 text-[11px] text-ink-subtle">{log.ip}</span>}
                                    {log.changes && (open === log.id ? (
                                        <Changes changes={log.changes} />
                                    ) : (
                                        <span className="ml-2 text-[11px] text-accent">details</span>
                                    ))}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-3 flex justify-between text-sm">
                {logs.prev_page_url ? <Link href={logs.prev_page_url} className="text-accent">← Newer</Link> : <span />}
                {logs.next_page_url ? <Link href={logs.next_page_url} className="text-accent">Older →</Link> : <span />}
            </div>
        </AppLayout>
    );
}
