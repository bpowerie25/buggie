import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import type { ProjectSummary } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';

export default function Dashboard({
    workspace,
    projects,
}: {
    workspace: { name: string; trial_ends_at: string | null };
    projects: ProjectSummary[];
}) {
    return (
        <AppLayout
            title="Dashboard"
            actions={
                <Link href="/projects/create">
                    <Button size="sm">
                        <Plus className="size-4" />
                        New project
                    </Button>
                </Link>
            }
        >
            <Head title="Dashboard" />

            <h2 className="text-xl font-semibold tracking-tight text-ink">
                {workspace.name}
            </h2>
            <p className="mt-1 text-sm text-ink-muted">
                {projects.length === 0
                    ? 'No projects yet. Create one to start tracking issues.'
                    : `${projects.length} active project${projects.length === 1 ? '' : 's'}.`}
            </p>

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
