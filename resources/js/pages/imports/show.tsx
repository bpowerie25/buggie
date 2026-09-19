import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
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
        title: string;
        status: string | null;
        type: string;
        assignee: boolean;
        notes: string[];
    }[];
}

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
        skipped: number;
        problems: { row: number; message: string }[];
        finished_at: string | null;
    };
    preview: Preview | null;
}) {
    const url = `/projects/${project.slug}/imports/${record.id}`;

    return (
        <AppLayout title="Import">
            <Head title={`Import · ${project.name}`} />

            <Link
                href={`/projects/${project.slug}/edit`}
                className="text-xs text-ink-subtle hover:text-ink"
            >
                {project.name}
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

                    <h3 className="mt-6 text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                        First few rows, as they would be created
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
                                    <span className="shrink-0 text-xs text-ink-subtle">
                                        {row.type} · {row.status}
                                    </span>
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
                        <Button onClick={() => router.patch(url)}>
                            Import {preview.total} issue{preview.total === 1 ? '' : 's'}
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
                            Imported {record.imported} issue
                            {record.imported === 1 ? '' : 's'}
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
