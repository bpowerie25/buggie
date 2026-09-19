import { TypeIcon } from '@/components/issue-bits';
import { AppLayout } from '@/layouts/app-layout';
import type { IssueTypeValue } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';

interface VersionIssue {
    key: string;
    title: string;
    type: IssueTypeValue;
    status: { name: string; open: boolean };
    labels: string[];
}

export default function ShowVersion({
    project,
    version,
    issues,
    can_manage,
}: {
    project: { name: string; key: string; slug: string };
    version: { id: number; name: string; description: string | null; released_at: string | null };
    issues: VersionIssue[];
    can_manage: boolean;
}) {
    const open = issues.filter((issue) => issue.status.open);
    const done = issues.filter((issue) => !issue.status.open);

    return (
        <AppLayout title={`${project.name} · ${version.name}`}>
            <Head title={`${version.name} · ${project.name}`} />

            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link
                        href={`/projects/${project.slug}`}
                        className="text-xs text-ink-subtle hover:text-ink"
                    >
                        {project.name}
                    </Link>
                    <h2 className="mt-1 text-2xl font-semibold tracking-tight text-ink">
                        {version.name}
                    </h2>
                    <p className="mt-1 text-sm text-ink-muted">
                        {version.released_at
                            ? `Released ${version.released_at}`
                            : `${open.length} still open of ${issues.length}`}
                    </p>
                </div>

                {can_manage && (
                    <button
                        type="button"
                        onClick={() =>
                            router.patch(
                                `/projects/${project.slug}/versions/${version.id}`,
                                { released: !version.released_at },
                                { preserveScroll: true },
                            )
                        }
                        className="rounded-lg border border-border px-3 py-1.5 text-sm text-ink-muted transition hover:text-ink"
                    >
                        {version.released_at ? 'Mark unreleased' : 'Mark released'}
                    </button>
                )}
            </div>

            {version.description && (
                <p className="mt-4 max-w-2xl text-pretty text-ink-muted">{version.description}</p>
            )}

            {issues.length === 0 ? (
                <p className="mt-8 text-sm text-ink-subtle">
                    Nothing is in this release yet. Put an issue in one from its own page.
                </p>
            ) : (
                <div className="mt-8 max-w-3xl space-y-8">
                    {/*
                        Done first. A changelog is read by somebody asking what
                        changed, not by somebody asking what is left.
                    */}
                    {done.length > 0 && <Section title="Shipped" issues={done} />}
                    {open.length > 0 && <Section title="Still open" issues={open} />}
                </div>
            )}
        </AppLayout>
    );
}

function Section({ title, issues }: { title: string; issues: VersionIssue[] }) {
    return (
        <section>
            <h3 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                {title} ({issues.length})
            </h3>

            <ul className="mt-2 divide-y divide-border rounded-xl border border-border bg-raised">
                {issues.map((issue) => (
                    <li key={issue.key}>
                        <Link
                            href={`/issues/${issue.key}`}
                            className="flex items-center gap-3 px-4 py-2.5 transition hover:bg-surface"
                        >
                            {issue.status.open ? (
                                <Circle className="size-3.5 shrink-0 text-ink-subtle" />
                            ) : (
                                <CheckCircle2 className="size-3.5 shrink-0 text-success" />
                            )}
                            <TypeIcon type={issue.type} />
                            <span className="min-w-0 flex-1 truncate text-sm text-ink">
                                {issue.title}
                            </span>
                            <span className="shrink-0 font-mono text-[11px] text-ink-subtle">
                                {issue.key}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
