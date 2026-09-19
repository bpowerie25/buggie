import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Download, Trash2 } from 'lucide-react';

interface Entry {
    id: number;
    minutes: number;
    duration: string;
    spent_on: string;
    note: string | null;
    billable: boolean;
    user: string;
    issue: { key: string; title: string; project: string | null } | null;
    can_delete: boolean;
}

interface Totals {
    minutes: number;
    duration: string;
    hours: string;
    billable_minutes: number;
    billable_duration: string;
    billable_hours: string;
    entries: number;
}

interface Group {
    name: string;
    minutes: number;
    duration: string;
    hours: string;
}

interface Filters {
    from: string;
    to: string;
    user_id: number | null;
    project_id: number | null;
    billable: string | null;
}

/** A sum somebody is about to invoice from, shown in both units it might be read in. */
function Total({ label, duration, hours }: { label: string; duration: string; hours: string }) {
    return (
        <div className="rounded-xl border border-border bg-raised p-4">
            <span className="text-xs text-ink-subtle">{label}</span>
            <p className="mt-1 text-2xl font-semibold text-ink">{duration}</p>
            <p className="text-xs text-ink-subtle">{hours} hours</p>
        </div>
    );
}

function Breakdown({ title, rows }: { title: string; rows: Group[] }) {
    if (rows.length === 0) return null;

    const largest = Math.max(...rows.map((row) => row.minutes), 1);

    return (
        <section className="rounded-xl border border-border p-4">
            <h2 className="text-sm font-semibold text-ink">{title}</h2>
            <ul className="mt-3 space-y-2">
                {rows.map((row) => (
                    <li key={row.name}>
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="truncate text-ink">{row.name}</span>
                            <span className="shrink-0 text-ink-muted">{row.duration}</span>
                        </div>
                        {/* A bar rather than a chart library: one proportion, no axes. */}
                        <div className="mt-1 h-1.5 rounded-full bg-border">
                            <div
                                className="h-1.5 rounded-full bg-accent"
                                style={{ width: `${(row.minutes / largest) * 100}%` }}
                            />
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default function TimeReport({
    entries,
    totals,
    byPerson,
    byProject,
    filters,
    people,
    projects,
}: {
    entries: Entry[];
    totals: Totals;
    byPerson: Group[];
    byProject: Group[];
    filters: Filters;
    people: { id: number; name: string }[];
    projects: { id: number; name: string }[];
}) {
    function apply(changes: Partial<Record<string, string | number | null>>) {
        const next: Record<string, string> = {};
        const merged = { ...filters, ...changes };

        Object.entries(merged).forEach(([key, value]) => {
            if (value !== null && value !== '') next[key] = String(value);
        });

        router.get('/time', next, { preserveState: true, preserveScroll: true });
    }

    const query = new URLSearchParams(
        Object.entries(filters).filter(([, v]) => v !== null && v !== '') as [string, string][],
    ).toString();

    const select =
        'rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-ink';

    return (
        <AppLayout
            title="Time"
            actions={
                // A plain anchor, not an Inertia link: Inertia would try to render the
                // CSV as a page. It carries the current filters, so what you export
                // is what you are looking at.
                <a
                    href={`/time/export?${query}`}
                    title="Download this time as CSV"
                    className="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                >
                    <Download className="size-3.5" />
                    Export
                </a>
            }
        >
            <Head title="Time" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        type="date"
                        value={filters.from}
                        aria-label="From"
                        onChange={(e) => apply({ from: e.target.value })}
                        className={select}
                    />
                    <span className="text-sm text-ink-subtle">to</span>
                    <input
                        type="date"
                        value={filters.to}
                        aria-label="To"
                        onChange={(e) => apply({ to: e.target.value })}
                        className={select}
                    />

                    <select
                        value={filters.user_id ?? ''}
                        aria-label="Person"
                        onChange={(e) => apply({ user_id: e.target.value || null })}
                        className={select}
                    >
                        <option value="">Everyone</option>
                        {people.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters.project_id ?? ''}
                        aria-label="Project"
                        onChange={(e) => apply({ project_id: e.target.value || null })}
                        className={select}
                    >
                        <option value="">Every project</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters.billable ?? ''}
                        aria-label="Billable"
                        onChange={(e) => apply({ billable: e.target.value || null })}
                        className={select}
                    >
                        <option value="">Billable and not</option>
                        <option value="1">Billable only</option>
                        <option value="0">Non-billable only</option>
                    </select>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <Total label="Total" duration={totals.duration} hours={totals.hours} />
                    <Total
                        label="Billable"
                        duration={totals.billable_duration}
                        hours={totals.billable_hours}
                    />
                    <div className="rounded-xl border border-border bg-raised p-4">
                        <span className="text-xs text-ink-subtle">Entries</span>
                        <p className="mt-1 text-2xl font-semibold text-ink">{totals.entries}</p>
                        <p className="text-xs text-ink-subtle">
                            {entries.length < totals.entries
                                ? `showing the most recent ${entries.length}`
                                : 'all shown'}
                        </p>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Breakdown title="By person" rows={byPerson} />
                    <Breakdown title="By project" rows={byProject} />
                </div>

                {entries.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-ink-muted">
                        No time logged in this period.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="border-b border-border text-left text-xs text-ink-subtle">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Date</th>
                                    <th className="px-3 py-2 font-medium">Person</th>
                                    <th className="px-3 py-2 font-medium">Issue</th>
                                    <th className="px-3 py-2 font-medium">Note</th>
                                    <th className="px-3 py-2 text-right font-medium">Time</th>
                                    <th className="w-8" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <td className="whitespace-nowrap px-3 py-2 text-ink-muted">
                                            {entry.spent_on}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-ink-muted">
                                            {entry.user}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.issue ? (
                                                <Link
                                                    href={`/issues/${entry.issue.key}`}
                                                    className="text-accent underline underline-offset-2"
                                                >
                                                    {entry.issue.key}
                                                </Link>
                                            ) : (
                                                <span className="text-ink-subtle">—</span>
                                            )}
                                            {entry.issue?.project && (
                                                <span className="ml-2 text-xs text-ink-subtle">
                                                    {entry.issue.project}
                                                </span>
                                            )}
                                        </td>
                                        <td className="max-w-xs truncate px-3 py-2 text-ink-muted">
                                            {entry.note}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right text-ink">
                                            {entry.duration}
                                            {!entry.billable && (
                                                <span className="ml-1.5 text-xs text-ink-subtle">
                                                    unbilled
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-1 py-2">
                                            {entry.can_delete && (
                                                <button
                                                    type="button"
                                                    aria-label="Remove entry"
                                                    className="rounded p-1 text-ink-subtle transition hover:text-danger"
                                                    onClick={() =>
                                                        router.delete(`/time/${entry.id}`, {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                >
                                                    <Trash2 className="size-3.5" />
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
