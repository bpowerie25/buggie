import { BacklogChart, BarList, ThroughputChart, type Point } from '@/components/charts';
import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

interface Headline {
    opened: number;
    closed: number;
    net: number;
    open_now: number;
    reports: number;
    collapsed: number;
}

interface Row {
    name: string;
    count: number;
}

function Stat({
    label,
    value,
    hint,
    tone = 'plain',
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    tone?: 'plain' | 'good' | 'bad';
}) {
    return (
        <div className="rounded-xl border border-border bg-raised p-4">
            <span className="text-xs text-ink-subtle">{label}</span>
            <p
                className={`mt-1 text-2xl font-semibold ${
                    tone === 'bad' ? 'text-danger' : tone === 'good' ? 'text-success' : 'text-ink'
                }`}
            >
                {value}
            </p>
            {hint && <p className="text-xs text-ink-subtle">{hint}</p>}
        </div>
    );
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-border p-4">
            <h2 className="text-sm font-semibold text-ink">{title}</h2>
            <div className="mt-3">{children}</div>
        </section>
    );
}

export default function InsightsPage({
    headline,
    median,
    throughput,
    interval,
    byProject,
    byAssignee,
    byStatus,
    ageing,
    blockers = [],
    delaysCaused = [],
    filters,
    projects,
}: {
    headline: Headline;
    median: { minutes: number | null; label: string | null };
    throughput: Point[];
    interval: string;
    byProject: Row[];
    byAssignee: Row[];
    byStatus: Row[];
    ageing: { key: string; title: string; project: string | null; status: string | null; days: number }[];
    blockers?: {
        key: string;
        title: string;
        project: string | null;
        status: string;
        assignee: string | null;
        waiting: number;
        chain: number;
        delay_days: number;
        since: string | null;
    }[];
    delaysCaused?: {
        blocker: string;
        key: string | null;
        title: string | null;
        days: number | null;
        by: string | null;
        at: string;
    }[];
    filters: { from: string; to: string; project_id: number | null };
    projects: { id: number; name: string }[];
}) {
    function apply(changes: Record<string, string | number | null>) {
        const merged = { ...filters, ...changes };
        const next: Record<string, string> = {};

        Object.entries(merged).forEach(([key, value]) => {
            if (value !== null && value !== '') next[key] = String(value);
        });

        router.get('/insights', next, { preserveState: true, preserveScroll: true });
    }

    const control = 'rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-ink';

    return (
        <AppLayout title="Insights">
            <Head title="Insights" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        type="date"
                        value={filters.from}
                        aria-label="From"
                        onChange={(e) => apply({ from: e.target.value })}
                        className={control}
                    />
                    <span className="text-sm text-ink-subtle">to</span>
                    <input
                        type="date"
                        value={filters.to}
                        aria-label="To"
                        onChange={(e) => apply({ to: e.target.value })}
                        className={control}
                    />
                    <select
                        value={filters.project_id ?? ''}
                        aria-label="Project"
                        onChange={(e) => apply({ project_id: e.target.value || null })}
                        className={control}
                    >
                        <option value="">Every project</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </select>

                    {/* Ranges somebody actually asks for, rather than making them
                        count backwards on a calendar. */}
                    {[
                        { label: '30 days', days: 29 },
                        { label: '90 days', days: 89 },
                        { label: 'A year', days: 364 },
                    ].map((preset) => (
                        <button
                            key={preset.label}
                            type="button"
                            onClick={() => {
                                const to = new Date();
                                const from = new Date();
                                from.setDate(to.getDate() - preset.days);

                                apply({
                                    from: from.toISOString().slice(0, 10),
                                    to: to.toISOString().slice(0, 10),
                                });
                            }}
                            className="rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                        >
                            {preset.label}
                        </button>
                    ))}
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Opened" value={headline.opened} />
                    <Stat label="Closed" value={headline.closed} />
                    <Stat
                        label="Backlog change"
                        value={headline.net > 0 ? `+${headline.net}` : headline.net}
                        hint={`${headline.open_now} open now`}
                        // Growing is the thing worth noticing; shrinking or level is
                        // not worth colouring.
                        tone={headline.net > 0 ? 'bad' : headline.net < 0 ? 'good' : 'plain'}
                    />
                    <Stat
                        label="Typical time to close"
                        value={median.label ?? '—'}
                        hint={median.label ? 'median' : 'nothing closed yet'}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title={`Opened and closed, by ${interval}`}>
                        <ThroughputChart points={throughput} interval={interval} />
                    </Panel>

                    <Panel title="Open issues over time">
                        <BacklogChart points={throughput} />
                    </Panel>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Stat
                        label="Reports received"
                        value={headline.reports}
                        hint="from the widget and the SDKs"
                    />
                    <Stat
                        label="Duplicate reports absorbed"
                        value={headline.collapsed}
                        hint="repeats of a bug already known"
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel title="Opened, by project">
                        <BarList rows={byProject} empty="Nothing opened in this period." />
                    </Panel>
                    <Panel title="Open, by assignee">
                        <BarList rows={byAssignee} empty="Nothing open." />
                    </Panel>
                    <Panel title="Open, by status">
                        <BarList rows={byStatus} empty="Nothing open." />
                    </Panel>
                </div>

                <Panel title="Blockers">
                    {blockers.length === 0 ? (
                        <p className="py-4 text-sm text-ink-subtle">Nothing open is waiting on anything open.</p>
                    ) : (
                        <>
                            <p className="mb-2 text-xs text-ink-subtle">
                                Open work other open work is waiting on, worst first. Red means the work
                                waiting can't start when it was planned to. Filter with{' '}
                                <Link href="/issues?q=is:delaying" className="font-mono text-accent">
                                    is:delaying
                                </Link>
                                ,{' '}
                                <Link href="/issues?q=is:blocking" className="font-mono text-accent">
                                    is:blocking
                                </Link>{' '}
                                or{' '}
                                <Link href="/issues?q=is:blocked" className="font-mono text-accent">
                                    is:blocked
                                </Link>
                                .
                            </p>
                            <ul className="divide-y divide-border">
                                {blockers.map((b) => (
                                    <li key={b.key} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                        <Link href={`/issues/${b.key}`} className="shrink-0 font-mono text-xs text-accent">
                                            {b.key}
                                        </Link>
                                        <span className="min-w-0 flex-1 truncate text-sm text-ink">{b.title}</span>
                                        <span className="shrink-0 text-xs text-ink-subtle">{b.assignee ?? 'Unassigned'}</span>
                                        <span className="shrink-0 text-xs text-ink-subtle">{b.status}</span>
                                        <span
                                            className="shrink-0 text-xs text-ink-muted"
                                            title={b.chain > b.waiting ? `${b.waiting} directly, ${b.chain} counting what waits on those` : undefined}
                                        >
                                            {b.chain} waiting
                                        </span>
                                        {b.since && <span className="shrink-0 text-xs text-ink-subtle">since {b.since}</span>}
                                        <span
                                            className={`w-24 shrink-0 text-right text-xs font-medium ${b.delay_days > 0 ? 'text-danger' : 'text-ink-subtle'}`}
                                        >
                                            {b.delay_days > 0 ? `${b.delay_days} day${b.delay_days === 1 ? '' : 's'} late` : 'On time'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </Panel>

                {delaysCaused.length > 0 && (
                    <Panel title="Delays caused">
                        <p className="mb-2 text-xs text-ink-subtle">
                            Work pushed later on the timeline because something it was waiting on moved.
                        </p>
                        <ul className="divide-y divide-border">
                            {delaysCaused.map((d, i) => (
                                <li key={i} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                                    <Link href={`/issues/${d.blocker}`} className="shrink-0 font-mono text-xs text-danger">
                                        {d.blocker}
                                    </Link>
                                    <span className="text-ink-subtle">pushed</span>
                                    {d.key && (
                                        <Link href={`/issues/${d.key}`} className="shrink-0 font-mono text-xs text-accent">
                                            {d.key}
                                        </Link>
                                    )}
                                    <span className="min-w-0 flex-1 truncate text-ink">{d.title}</span>
                                    {d.days !== null && (
                                        <span className="shrink-0 text-xs font-medium text-danger">
                                            {d.days} day{d.days === 1 ? '' : 's'}
                                        </span>
                                    )}
                                    <span className="shrink-0 text-xs text-ink-subtle">
                                        {d.at}
                                        {d.by ? ` · ${d.by}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                <Panel title="Open longest">
                    {ageing.length === 0 ? (
                        <p className="py-4 text-sm text-ink-subtle">Nothing open.</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {ageing.map((issue) => (
                                <li key={issue.key} className="flex items-center gap-3 py-2">
                                    <Link
                                        href={`/issues/${issue.key}`}
                                        className="shrink-0 font-mono text-xs text-accent"
                                    >
                                        {issue.key}
                                    </Link>
                                    <span className="min-w-0 flex-1 truncate text-sm text-ink">
                                        {issue.title}
                                    </span>
                                    <span className="shrink-0 text-xs text-ink-subtle">
                                        {issue.project}
                                    </span>
                                    <span className="shrink-0 text-xs text-ink-subtle">
                                        {issue.status}
                                    </span>
                                    <span className="w-20 shrink-0 text-right text-xs text-ink-muted">
                                        {issue.days} days
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
