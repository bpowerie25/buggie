import { Button } from '@/components/button';
import { Field } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent } from 'react';

interface ProjectRef {
    id: number;
    name: string;
    key: string;
    slug: string;
}

/**
 * Bringing issues in, or bringing changes to them back in.
 *
 * Its own page, for anybody on the staff: a spreadsheet of work from a client, a
 * backlog from another tracker, or the team's own export edited and uploaded again.
 * Nothing is created or changed until the preview has been read and confirmed.
 */
export default function ImportIndex({
    project,
    projects,
    statuses,
    phases,
    recent,
}: {
    project: ProjectRef | null;
    projects: ProjectRef[];
    statuses: string[];
    phases: string[];
    recent: {
        id: number;
        filename: string;
        state: string;
        imported: number;
        updated: number;
        skipped: number;
        by: string | null;
        at: string;
    }[];
}) {
    const { data, setData, post, processing, errors } = useForm({ file: null as File | null });

    function submit(e: FormEvent) {
        e.preventDefault();
        if (project) post(`/projects/${project.slug}/imports`, { forceFormData: true });
    }

    return (
        <AppLayout title="Import issues">
            <Head title={project ? `Import · ${project.name}` : 'Import issues'} />

            <div className="max-w-2xl space-y-8">
                <div className="flex flex-wrap items-center gap-2">
                    <label className="text-sm text-ink-muted" htmlFor="import-project">
                        Into
                    </label>
                    <select
                        id="import-project"
                        value={project?.slug ?? ''}
                        onChange={(e) => e.target.value && router.visit(`/projects/${e.target.value}/import`)}
                        className="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-ink"
                    >
                        {!project && <option value="">Choose a project…</option>}
                        {projects.map((p) => (
                            <option key={p.id} value={p.slug}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </div>

                {project && (
                    <>
                        <section>
                            <h2 className="text-sm font-semibold text-ink">1. Start from the template</h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                Or use a CSV export from Jira or MantisBT, or one exported from Buggie's issue
                                list. Keep the header row and save as CSV. Only Title is needed for a new issue.
                            </p>

                            {/* A plain link, not an Inertia visit: it is a file download. */}
                            <div className="mt-3 rounded-lg border border-border bg-surface p-3 text-xs text-ink-muted">
                                <a
                                    href={`/projects/${project.slug}/imports/template`}
                                    className="inline-flex items-center gap-1.5 font-medium text-accent hover:underline"
                                    download
                                >
                                    <Download className="size-3.5" />
                                    Download a template for {project.key}
                                </a>
                                <p className="mt-1.5">
                                    Opens in Excel, Numbers or Google Sheets. The three EXAMPLE rows are skipped,
                                    so leaving them in does no harm.
                                </p>
                                <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                    <dt className="font-medium text-ink">Key</dt>
                                    <dd>
                                        Your own reference, or an existing issue's key like {project.key}-12 to update
                                        it. See step 3.
                                    </dd>
                                    <dt className="font-medium text-ink">Status</dt>
                                    <dd>{statuses.join(', ')}; anything that sounds finished counts as done</dd>
                                    <dt className="font-medium text-ink">Priority</dt>
                                    <dd>Urgent, High, Medium, Low, or blank</dd>
                                    <dt className="font-medium text-ink">Type</dt>
                                    <dd>Bug, Feature, Task, Question</dd>
                                    <dt className="font-medium text-ink">Assignee</dt>
                                    <dd>A team member's email or exact name</dd>
                                    <dt className="font-medium text-ink">Start, Due</dt>
                                    <dd>Dates, like 2026-10-14 or 14/10/2026 (day first)</dd>
                                    <dt className="font-medium text-ink">Estimate</dt>
                                    <dd>Hours, like 4 or 2.5, or 3h 30m</dd>
                                    <dt className="font-medium text-ink">Phase</dt>
                                    <dd>
                                        {phases.length > 0 ? phases.join(', ') : 'A phase name'}; a new name creates that
                                        phase
                                    </dd>
                                    <dt className="font-medium text-ink">Parent</dt>
                                    <dd>
                                        The issue this is a subtask of: {project.key}-12, or another row's Key in the same
                                        file
                                    </dd>
                                    <dt className="font-medium text-ink">Created</dt>
                                    <dd>When it was first raised, if you want to keep the history</dd>
                                </dl>
                            </div>
                        </section>

                        <section>
                            <h2 className="text-sm font-semibold text-ink">2. Upload it</h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                You'll see what it will create and change before anything happens.
                            </p>
                            <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3">
                                <div className="flex-1">
                                    <Field label="CSV file" error={errors.file}>
                                        <input
                                            type="file"
                                            accept=".csv,text/csv"
                                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                                            className="w-full rounded-lg border border-border bg-raised px-3 py-2 text-sm text-ink-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface file:px-2 file:py-1 file:text-xs file:text-ink"
                                        />
                                    </Field>
                                </div>
                                <Button type="submit" size="sm" disabled={processing || !data.file}>
                                    Upload
                                </Button>
                            </form>
                        </section>

                        <section>
                            <h2 className="text-sm font-semibold text-ink">3. Updating issues that are already here</h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                A row whose Key is an existing issue ({project.key}-12), or the key an earlier import
                                brought it in under, matches that issue. On the preview you choose whether matching
                                rows update their issue or are skipped. When updating, only filled-in cells change
                                anything; a blank cell leaves that field as it is. Every change appears in the issue's
                                activity, as if made by hand.
                            </p>
                            <p className="mt-2 text-sm text-ink-muted">
                                The quickest way to update many issues: filter the{' '}
                                <Link href={`/issues?q=${encodeURIComponent(`project:${project.slug}`)}`} className="text-accent hover:underline">
                                    issue list
                                </Link>
                                , use Export, edit the file, and import it here.
                            </p>
                        </section>

                        {recent.length > 0 && (
                            <section>
                                <h2 className="text-sm font-semibold text-ink">Recent imports</h2>
                                <ul className="mt-2 divide-y divide-border rounded-xl border border-border bg-raised">
                                    {recent.map((r) => (
                                        <li key={r.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
                                            <Link
                                                href={`/projects/${project.slug}/imports/${r.id}`}
                                                className="min-w-0 flex-1 truncate text-ink hover:text-accent"
                                            >
                                                {r.filename}
                                            </Link>
                                            <span className="text-xs text-ink-muted">
                                                {r.state === 'done'
                                                    ? `${r.imported} created · ${r.updated} updated · ${r.skipped} skipped`
                                                    : r.state === 'importing'
                                                      ? 'Importing…'
                                                      : 'Not started'}
                                            </span>
                                            <span className="text-xs text-ink-subtle">
                                                {r.at}
                                                {r.by ? ` · ${r.by}` : ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
