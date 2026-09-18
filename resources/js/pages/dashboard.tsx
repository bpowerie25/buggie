import { Button } from '@/components/button';
import { relativeTime, TypeIcon } from '@/components/issue-bits';
import { AppLayout } from '@/layouts/app-layout';
import type { IssueTypeValue, ProjectSummary, SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';

interface WaitingIssue {
    key: string;
    title: string;
    type: IssueTypeValue;
    project: string;
    status: { name: string; color: string };
    updated_at: string;
}

export default function Dashboard({
    workspace,
    projects,
    waitingOnYou = [],
}: {
    workspace: { name: string; trial_ends_at: string | null };
    projects: ProjectSummary[];
    waitingOnYou?: WaitingIssue[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const isClient = auth.role === 'client';
    return (
        <AppLayout
            title="Dashboard"
            actions={
                isClient ? undefined : (
                    <Link href="/projects/create">
                        <Button size="sm">
                            <Plus className="size-4" />
                            New project
                        </Button>
                    </Link>
                )
            }
        >
            <Head title="Dashboard" />

            <h2 className="text-xl font-semibold tracking-tight text-ink">
                {workspace.name}
            </h2>
            <p className="mt-1 text-sm text-ink-muted">
                {projects.length === 0
                    ? isClient
                        ? 'You have not been given access to a project yet.'
                        : 'No projects yet. Create one to start tracking issues.'
                    : `${projects.length} active project${projects.length === 1 ? '' : 's'}.`}
            </p>

            {/*
                Shown above the projects, because it is the thing that needs doing.
                A client is usually here because the team asked them something.
            */}
            {waitingOnYou.length > 0 && (
                <section className="mt-6">
                    <h3 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                        Waiting on you
                    </h3>

                    <ul className="mt-2 divide-y divide-border rounded-xl border border-border bg-raised">
                        {waitingOnYou.map((issue) => (
                            <li key={issue.key}>
                                <Link
                                    href={`/issues/${issue.key}`}
                                    className="flex items-center gap-3 px-4 py-3 transition hover:bg-surface"
                                >
                                    <TypeIcon type={issue.type} />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm text-ink">
                                            {issue.title}
                                        </span>
                                        <span className="text-xs text-ink-subtle">
                                            {issue.project} · {issue.status.name} ·{' '}
                                            {relativeTime(issue.updated_at)}
                                        </span>
                                    </span>
                                    <span className="shrink-0 font-mono text-[11px] text-ink-subtle">
                                        {issue.key}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {projects.length > 0 && (
                <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {projects.map((project) => (
                        <li key={project.slug}>
                            <Link
                                href={`/projects/${project.slug}`}
                                className="block h-full rounded-xl border border-border bg-raised p-4 transition hover:border-border-strong"
                            >
                                <div className="flex items-center gap-2">
                                    <FolderKanban className="size-4 text-ink-subtle" />
                                    <span className="rounded bg-surface px-1.5 py-0.5 font-mono text-[11px] font-medium text-ink-muted">
                                        {project.key}
                                    </span>
                                </div>
                                <p className="mt-2 text-sm font-medium text-ink">
                                    {project.name}
                                </p>
                                {project.description && (
                                    <p className="mt-1 line-clamp-2 text-xs text-ink-muted">
                                        {project.description}
                                    </p>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
