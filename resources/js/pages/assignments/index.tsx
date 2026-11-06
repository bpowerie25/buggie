import { AppLayout } from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

interface ProjectCol {
    id: number;
    name: string;
    key: string;
    slug: string;
}

interface Row {
    id: number | null;
    name: string;
    cells: Record<number, number>;
    total: number;
    overdue: number;
}

const listUrl = (row: Row, project?: ProjectCol) =>
    `/issues?q=${encodeURIComponent(
        [row.id === null ? 'no:assignee' : `assignee:${row.id}`, project ? `project:${project.slug}` : '']
            .filter(Boolean)
            .join(' '),
    )}`;

/**
 * Everyone against every project, in open issues. Each number opens the list it counts,
 * and the list's bulk edit reassigns — useful the morning somebody is off sick.
 */
export default function AssignmentsPage({
    projects,
    people,
    unassigned,
    can_reassign,
}: {
    projects: ProjectCol[];
    people: Row[];
    unassigned: Row;
    can_reassign: boolean;
}) {
    const rows = [...people, unassigned];
    const columnTotal = (p: ProjectCol) => rows.reduce((sum, r) => sum + (r.cells[p.id] ?? 0), 0);

    const cell = (row: Row, project?: ProjectCol) => {
        const n = project ? (row.cells[project.id] ?? 0) : row.total;

        return n === 0 ? (
            <span className="text-ink-subtle">–</span>
        ) : (
            <Link
                href={listUrl(row, project)}
                className="inline-block min-w-8 rounded-md px-2 py-0.5 font-medium text-ink tabular-nums transition hover:bg-accent-soft hover:text-accent"
            >
                {n}
            </Link>
        );
    };

    return (
        <AppLayout title="Assignments">
            <Head title="Assignments" />

            <p className="max-w-2xl text-sm text-pretty text-ink-muted">
                Open issues by person and project. Click a number to see exactly those issues
                {can_reassign
                    ? '; select them there and use Assignee to hand them to someone else.'
                    : '.'}
            </p>

            <div className="mt-5 overflow-x-auto rounded-xl border border-border bg-raised">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-ink-subtle">
                            <th className="px-4 py-2.5 font-medium">Person</th>
                            {projects.map((p) => (
                                <th key={p.id} className="px-3 py-2.5 text-center font-medium" title={p.name}>
                                    {p.key}
                                </th>
                            ))}
                            <th className="px-3 py-2.5 text-center font-medium">Open</th>
                            <th className="px-3 py-2.5 text-center font-medium">Overdue</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {rows.map((row) => (
                            <tr key={row.id ?? 'none'} className="hover:bg-surface">
                                <td className="px-4 py-2 text-ink">
                                    <Link href={listUrl(row)} className="hover:text-accent hover:underline">
                                        {row.name}
                                    </Link>
                                </td>
                                {projects.map((p) => (
                                    <td key={p.id} className="px-3 py-2 text-center">
                                        {cell(row, p)}
                                    </td>
                                ))}
                                <td className="px-3 py-2 text-center font-semibold">{cell(row)}</td>
                                <td
                                    className={`px-3 py-2 text-center tabular-nums ${
                                        row.overdue > 0 ? 'font-medium text-danger' : 'text-ink-subtle'
                                    }`}
                                >
                                    {row.overdue || '–'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t border-border-strong text-xs text-ink-muted">
                            <td className="px-4 py-2 font-medium">Total</td>
                            {projects.map((p) => (
                                <td key={p.id} className="px-3 py-2 text-center tabular-nums">
                                    {columnTotal(p)}
                                </td>
                            ))}
                            <td className="px-3 py-2 text-center tabular-nums">
                                {rows.reduce((s, r) => s + r.total, 0)}
                            </td>
                            <td className="px-3 py-2 text-center tabular-nums">
                                {rows.reduce((s, r) => s + r.overdue, 0)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </AppLayout>
    );
}
