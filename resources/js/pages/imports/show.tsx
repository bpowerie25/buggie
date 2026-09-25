import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, ArrowRight, CheckCircle2 } from 'lucide-react';

interface Preview {
    error?: string;
    format?: string;
    total?: number;
    capped?: boolean;
    max?: number;
    mapped?: string[];
    ignored?: string[];
    headers?: string[];
    rows?: {
        source_key: string | null;
        /** Left in from the template; skipped. */
        example: boolean;
        title: string;
        status: string | null;
        type: string;
        assignee: boolean;
        /** The existing issue this row matches, and what it would change on it. */
        matches: string | null;
        changes: string[];
        notes: string[];
    }[];
    totals?: { new: number; matching: number; examples: number };
}

/** The names UpdateIssue and the mapper use, as a person reads them. */
const FIELD_LABELS: Record<string, string> = {
    title: 'title',
    description: 'description',
    status_id: 'status',
    priority: 'priority',
    type: 'type',
    assignee_id: 'assignee',
    start_on: 'start',
    due_on: 'due',
    estimate_minutes: 'estimate',
    phase: 'phase',
    parent: 'parent',
};

export default function ShowImport({
    project,
    import: record,
    preview,
}: {
    project: { name: string; key: string; slug: string };
    import: {
        id: number;
        filename: string;
        state: string;
        imported: number;
        updated: number;
        skipped: number;
        update_existing: boolean;
        problems: { row: number; message: string }[];
        finished_at: string | null;
    };
    preview: Preview | null;
}) {
    const url = `/projects/${project.slug}/imports/${record.id}`;
    const [update, setUpdate] = useState(false);
    const totals = preview?.totals;
    const creating = totals?.new ?? preview?.total ?? 0;
    const matching = totals?.matching ?? 0;

    return (
        <AppLayout title="Import">
            <Head title={`Import · ${project.name}`} />

            <Link
                href={`/projects/${project.slug}/import`}
                className="text-xs text-ink-subtle hover:text-ink"
            >
                {project.name} · Import
            </Link>

            <h2 className="mt-1 text-2xl font-semibold tracking-tight text-ink">
                {record.filename}
            </h2>

            {preview?.error ? (
                <div className="mt-6 max-w-2xl rounded-xl border border-danger/30 bg-danger-soft p-4">
                    <p className="text-sm text-danger">{preview.error}</p>
                    {preview.headers && (
                        <p className="mt-2 font-mono text-xs text-ink-subtle">
                            Columns found: {preview.headers.join(', ')}
                        </p>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="mt-3"
                        onClick={() => router.delete(url)}
                    >
                        Start again
                    </Button>
                </div>
            ) : record.state === 'previewing' && preview ? (
                <div className="mt-6 max-w-3xl">
                    <p className="text-sm text-ink-muted">
                        Looks like a <strong className="text-ink">{preview.format}</strong> export
                        with <strong className="text-ink">{preview.total}</strong> row
                        {preview.total === 1 ? '' : 's'}. Nothing has been created yet.
                    </p>

                    {preview.capped && (
                        <p className="mt-2 text-sm text-amber-600 dark:text-amber-500">
                            Only the first {preview.max?.toLocaleString()} rows will be imported.
                        </p>
                    )}

                    {preview.ignored && preview.ignored.length > 0 && (
                        <p className="mt-2 text-xs text-ink-subtle">
                            Columns not used: {preview.ignored.join(', ')}
                        </p>
                    )}

                    {totals && (
                        <p className="mt-2 text-sm text-ink-muted">
                            <strong className="text-ink">{totals.new}</strong> new
                            {totals.matching > 0 && (
                                <>
                                    , <strong className="text-ink">{totals.matching}</strong> matching issues already here
                                </>
                            )}
                            {totals.examples > 0 && `, ${totals.examples} example row${totals.examples === 1 ? '' : 's'} to skip`}.
                        </p>
                    )}

                    {matching > 0 && (
                        <fieldset className="mt-4 space-y-1.5 rounded-xl border border-border p-3">
                            <legend className="px-1 text-sm font-medium text-ink">
                                {matching} row{matching === 1 ? '' : 's'} match{matching === 1 ? 'es' : ''} an existing issue
                            </legend>
                            <label className="flex items-start gap-2 text-sm text-ink-muted">
                                <input type="radio" checked={!update} onChange={() => setUpdate(false)} className="mt-0.5" />
                                <span>
                                    Skip them
                                    <span className="block text-xs text-ink-subtle">Safe to run the same file twice.</span>
                                </span>
                            </label>
                            <label className="flex items-start gap-2 text-sm text-ink-muted">
                                <input type="radio" checked={update} onChange={() => setUpdate(true)} className="mt-0.5" />
                                <span>
                                    Update them from the file
                                    <span className="block text-xs text-ink-subtle">
                                        Only filled-in cells change anything. Each change shows in the issue's activity.
                                    </span>
                                </span>
                            </label>
                        </fieldset>
                    )}

                    <h3 className="mt-6 text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                        First few rows, and what would happen to each
                    </h3>

                    <ul className="mt-2 divide-y divide-border rounded-xl border border-border bg-raised">
                        {preview.rows?.map((row, i) => (
                            <li key={i} className="px-4 py-2.5">
                                <div className="flex items-center gap-3">
                                    {row.source_key && (
                                        <span className="shrink-0 font-mono text-[11px] text-ink-subtle">
                                            {row.source_key}
                                        </span>
                                    )}
                                    <span className="min-w-0 flex-1 truncate text-sm text-ink">
                                        {row.title}
                                    </span>
                                    {row.matches ? (
                                        <span className="shrink-0 text-xs text-ink-subtle">
                                            {update ? (
                                                row.changes.length > 0 ? (
                                                    <>
                                                        Updates <span className="font-mono">{row.matches}</span>:{' '}
                                                        {row.changes.map((c) => FIELD_LABELS[c] ?? c).join(', ')}
                                                    </>
                                                ) : (
                                                    <>
                                                        <span className="font-mono">{row.matches}</span> unchanged
                                                    </>
                                                )
                                            ) : (
                                                <>
                                                    Skipped: matches <span className="font-mono">{row.matches}</span>
                                                </>
                                            )}
                                        </span>
                                    ) : (
                                        <span className="shrink-0 text-xs text-ink-subtle">
                                            {row.example ? 'Example, skipped' : `New · ${row.type} · ${row.status}`}
                                        </span>
                                    )}
                                </div>

                                {row.notes.length > 0 && (
                                    <p className="mt-1 text-xs text-amber-600 dark:text-amber-500">
                                        {row.notes.join(' ')}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>

                    <div className="mt-6 flex gap-3">
                        <Button
                            disabled={creating === 0 && !(update && matching > 0)}
                            onClick={() => router.patch(url, { update_existing: update })}
                        >
                            {update && matching > 0
                                ? `Create ${creating}, update ${matching}`
                                : `Import ${creating} issue${creating === 1 ? '' : 's'}`}
                            <ArrowRight className="size-4" />
                        </Button>
                        <Button variant="ghost" onClick={() => router.delete(url)}>
                            Cancel
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="mt-6 max-w-3xl">
                    {record.state === 'importing' ? (
                        <p className="text-sm text-ink-muted">
                            Importing in the background. Reload in a moment.
                        </p>
                    ) : (
                        <p className="flex items-center gap-2 text-sm text-ink">
                            <CheckCircle2 className="size-4 text-success" />
                            Created {record.imported} issue
                            {record.imported === 1 ? '' : 's'}
                            {record.updated > 0 && `, updated ${record.updated}`}
                            {record.skipped > 0 && `, skipped ${record.skipped}`}.
                        </p>
                    )}

                    {record.problems.length > 0 && (
                        <>
                            <h3 className="mt-6 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                                <AlertTriangle className="size-3.5" />
                                Worth a look
                            </h3>

                            <ul className="mt-2 divide-y divide-border rounded-xl border border-border bg-raised text-sm">
                                {record.problems.map((problem, i) => (
                                    <li key={i} className="flex gap-3 px-4 py-2">
                                        <span className="shrink-0 font-mono text-[11px] text-ink-subtle">
                                            row {problem.row}
                                        </span>
                                        <span className="min-w-0 text-ink-muted">
                                            {problem.message}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}

                    {record.state === 'done' && (
                        <Link href="/issues" className="mt-6 inline-block">
                            <Button>See the issues</Button>
                        </Link>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
