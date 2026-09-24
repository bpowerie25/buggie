import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { hours, load } from '@/lib/workload';
import { Fragment, useState } from 'react';

interface Cell {
    minutes: number;
    issues: { key: string; title: string; project: string; minutes: number }[];
}

interface Person {
    id: number;
    name: string;
    discipline: string | null;
    weekly_minutes: number | null;
    cells: Record<string, Cell>;
    unestimated: number;
    undated: number;
}

interface Actual {
    id: number;
    name: string;
    discipline: string | null;
    closed: number;
    estimated: number;
    actual: number;
    logged: number;
    available: number | null;
}

const TONE = {
    none: 'text-ink-subtle',
    ok: 'bg-success/10 text-ink',
    near: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    over: 'bg-danger-soft text-danger font-medium',
    unknown: 'bg-surface text-ink',
};

/**
 * Who has how much on, week by week, across every project.
 *
 * Each cell is the estimated work left on that person's open, dated issues, spread
 * over the working days each one runs. Red is more than their weekly hours. Work with
 * no estimate or no dates cannot be put in a week, so it is counted beside the grid.
 */
export default function WorkloadPage({
    weeks,
    groups,
    unassigned,
    actuals,
    today,
    filters,
    projects,
    canManage,
}: {
    weeks: string[];
    groups: { discipline: string; people: Person[] }[];
    unassigned: Record<string, number>;
    actuals: Actual[];
    today: string;
    filters: { from: string; weeks: number; project_id: number | null; since: number };
    projects: { id: number; name: string }[];
    canManage: boolean;
}) {
    const [open, setOpen] = useState<{ person: number; week: string } | null>(null);

    function apply(changes: Partial<typeof filters>) {
        const next: Record<string, string> = {};
        Object.entries({ ...filters, ...changes }).forEach(([k, v]) => {
            if (v !== null && v !== '') next[k] = String(v);
        });

        router.get('/workload', next, { preserveState: true, preserveScroll: true });
    }

    const thisWeek = weeks.find((w, i) => w <= today && (weeks[i + 1] ?? '9999') > today);
    const people = groups.flatMap((g) => g.people);
    const chosen = open ? people.find((p) => p.id === open.person) : undefined;
    const chosenCell = chosen && open ? chosen.cells[open.week] : undefined;
    const unset = people.filter((p) => p.weekly_minutes === null).length;
    const control = 'rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-ink';

    return (
        <AppLayout title="Workload">
            <Head title="Workload" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        type="date"
                        value={filters.from}
                        aria-label="Starting the week of"
                        onChange={(e) => apply({ from: e.target.value })}
                        className={control}
                    />
                    <select
                        value={filters.weeks}
                        aria-label="Weeks"
                        onChange={(e) => apply({ weeks: Number(e.target.value) })}
                        className={control}
                    >
                        {[4, 8, 12, 26].map((w) => (
                            <option key={w} value={w}>
                                {w} weeks
                            </option>
                        ))}
                    </select>
                    <select
                        value={filters.project_id ?? ''}
                        aria-label="Project"
                        onChange={(e) => apply({ project_id: e.target.value ? Number(e.target.value) : null })}
                        className={control}
                    >
                        <option value="">Every project</option>
                        {projects.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </div>

                <p className="text-xs text-ink-muted">
                    Each week is the estimated work left on each person's open, dated issues (the estimate
                    minus time already logged), spread over the working days each issue runs. Late work
                    counts from today. Amber is 85% of their weekly hours, red is over.
                    {unset > 0 &&
                        (canManage ? (
                            <>
                                {' '}
                                {unset} {unset === 1 ? 'person has' : 'people have'} no weekly hours set yet —{' '}
                                <Link href="/settings/members" className="text-accent">
                                    set them on Members
                                </Link>
                                .
                            </>
                        ) : (
                            ` ${unset} ${unset === 1 ? 'person has' : 'people have'} no weekly hours set yet, so their weeks are not coloured.`
                        ))}
                </p>

                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full min-w-max border-collapse text-sm">
                        <thead>
                            <tr className="border-b border-border text-xs text-ink-subtle">
                                <th className="sticky left-0 z-10 bg-canvas px-3 py-2 text-left font-medium">Person</th>
                                {weeks.map((w) => (
                                    <th
                                        key={w}
                                        className={`px-2 py-2 text-center font-medium ${w === thisWeek ? 'text-accent' : ''}`}
                                    >
                                        {new Date(`${w}T00:00:00Z`).toLocaleDateString(undefined, {
                                            timeZone: 'UTC',
                                            day: 'numeric',
                                            month: 'short',
                                        })}
                                    </th>
                                ))}
                                <th className="px-3 py-2 text-left font-medium">Not in the plan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) => (
                                <Fragment key={group.discipline}>
                                    <tr className="bg-surface">
                                        <td
                                            colSpan={weeks.length + 2}
                                            className="sticky left-0 px-3 py-1.5 text-xs font-semibold text-ink"
                                        >
                                            {group.discipline}
                                        </td>
                                    </tr>
                                    {group.people.map((person) => (
                                        <tr key={person.id} className="border-t border-border">
                                            <td className="sticky left-0 z-10 bg-canvas px-3 py-1.5">
                                                <span className="block text-ink">{person.name}</span>
                                                <span className="text-[11px] text-ink-subtle">
                                                    {person.weekly_minutes === null
                                                        ? 'Hours not set'
                                                        : `${hours(person.weekly_minutes)} a week`}
                                                </span>
                                            </td>
                                            {weeks.map((w) => {
                                                const cell = person.cells[w];
                                                const tone = load(cell.minutes, person.weekly_minutes);
                                                const selected = open?.person === person.id && open.week === w;

                                                return (
                                                    <td key={w} className="px-1 py-1 text-center">
                                                        <button
                                                            type="button"
                                                            disabled={cell.minutes === 0}
                                                            onClick={() => setOpen(selected ? null : { person: person.id, week: w })}
                                                            title={
                                                                person.weekly_minutes
                                                                    ? `${hours(cell.minutes)} of ${hours(person.weekly_minutes)}`
                                                                    : hours(cell.minutes)
                                                            }
                                                            className={`w-full rounded-md px-1.5 py-1.5 text-xs tabular-nums ${TONE[tone]} ${
                                                                selected ? 'ring-2 ring-accent' : ''
                                                            }`}
                                                        >
                                                            {cell.minutes === 0 ? '–' : hours(cell.minutes)}
                                                        </button>
                                                    </td>
                                                );
                                            })}
                                            <td className="px-3 py-1.5 text-xs text-ink-muted">
                                                {person.unestimated > 0 && (
                                                    <Link
                                                        href={`/issues?q=${encodeURIComponent(`assignee:${person.id} no:estimate`)}`}
                                                        className="block hover:text-accent"
                                                    >
                                                        {person.unestimated} with no estimate
                                                    </Link>
                                                )}
                                                {person.undated > 0 && (
                                                    <Link
                                                        href={`/issues?q=${encodeURIComponent(`assignee:${person.id} no:dates`)}`}
                                                        className="block hover:text-accent"
                                                    >
                                                        {person.undated} with no dates
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </Fragment>
                            ))}

                            {Object.values(unassigned).some((m) => m > 0) && (
                                <tr className="border-t border-border">
                                    <td className="sticky left-0 z-10 bg-canvas px-3 py-1.5">
                                        <Link
                                            href={`/issues?q=${encodeURIComponent('assignee:none')}`}
                                            className="block text-ink hover:text-accent"
                                        >
                                            Nobody yet
                                        </Link>
                                        <span className="text-[11px] text-ink-subtle">Planned, not assigned</span>
                                    </td>
                                    {weeks.map((w) => (
                                        <td key={w} className="px-1 py-1 text-center text-xs text-ink-muted tabular-nums">
                                            {unassigned[w] ? hours(unassigned[w]) : '–'}
                                        </td>
                                    ))}
                                    <td />
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {chosen && chosenCell && open && (
                    <section className="rounded-xl border border-border p-4">
                        <h2 className="text-sm font-semibold text-ink">
                            {chosen.name}, week of {open.week}: {hours(chosenCell.minutes)}
                            {chosen.weekly_minutes !== null && ` of ${hours(chosen.weekly_minutes)}`}
                        </h2>
                        <ul className="mt-2 divide-y divide-border">
                            {chosenCell.issues.map((issue) => (
                                <li key={issue.key} className="flex items-center gap-3 py-1.5 text-sm">
                                    <Link href={`/issues/${issue.key}`} className="shrink-0 font-mono text-xs text-accent">
                                        {issue.key}
                                    </Link>
                                    <span className="min-w-0 flex-1 truncate text-ink">{issue.title}</span>
                                    <span className="shrink-0 text-xs text-ink-subtle">{issue.project}</span>
                                    <span className="w-12 shrink-0 text-right text-xs text-ink-muted tabular-nums">
                                        {hours(issue.minutes)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="rounded-xl border border-border p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-sm font-semibold text-ink">Estimates against actuals</h2>
                        <select
                            value={filters.since}
                            aria-label="Over the last"
                            onChange={(e) => apply({ since: Number(e.target.value) })}
                            className="ml-auto rounded-lg border border-border bg-surface px-2 py-1 text-xs text-ink"
                        >
                            {[30, 90, 180, 365].map((d) => (
                                <option key={d} value={d}>
                                    Last {d} days
                                </option>
                            ))}
                        </select>
                    </div>
                    <p className="mt-1 text-xs text-ink-subtle">
                        For issues each person finished in the period that had an estimate: the estimates added
                        up, against every hour logged on them. Then their own logged hours against the hours
                        they had.
                    </p>
                    <table className="mt-3 w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-ink-subtle">
                                <th className="py-1.5 font-medium">Person</th>
                                <th className="py-1.5 text-right font-medium">Finished</th>
                                <th className="py-1.5 text-right font-medium">Estimated</th>
                                <th className="py-1.5 text-right font-medium">Took</th>
                                <th className="py-1.5 text-right font-medium">Logged / available</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {actuals.map((a) => {
                                const over = a.estimated > 0 ? Math.round((a.actual / a.estimated - 1) * 100) : null;

                                return (
                                    <tr key={a.id}>
                                        <td className="py-1.5 text-ink">{a.name}</td>
                                        <td className="py-1.5 text-right text-ink-muted tabular-nums">{a.closed}</td>
                                        <td className="py-1.5 text-right text-ink-muted tabular-nums">
                                            {a.estimated ? hours(a.estimated) : '–'}
                                        </td>
                                        <td className="py-1.5 text-right tabular-nums">
                                            {a.estimated ? (
                                                <span className={over !== null && over > 10 ? 'text-danger' : 'text-ink'}>
                                                    {hours(a.actual)}
                                                    {over !== null && over !== 0 && (
                                                        <span className="ml-1 text-xs">
                                                            ({over > 0 ? `${over}% over` : `${-over}% under`})
                                                        </span>
                                                    )}
                                                </span>
                                            ) : (
                                                '–'
                                            )}
                                        </td>
                                        <td className="py-1.5 text-right text-ink-muted tabular-nums">
                                            {hours(a.logged)}
                                            {a.available ? ` of ${hours(a.available)} (${Math.round((a.logged / a.available) * 100)}%)` : ''}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </section>
            </div>
        </AppLayout>
    );
}
