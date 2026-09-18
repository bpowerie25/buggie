import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import type { ProjectSummary } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

export default function ProjectsIndex({
    projects,
}: {
    projects: ProjectSummary[];
}) {
    return (
        <AppLayout
            title="Projects"
            actions={
                <Link href="/projects/create">
                    <Button size="sm">
                        <Plus className="size-4" />
                        New project
                    </Button>
                </Link>
            }
        >
            <Head title="Projects" />

            {projects.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border-strong p-12 text-center">
                    <p className="text-sm font-medium text-ink">No projects yet</p>
                    <p className="mt-1 text-sm text-ink-muted">
                        A project groups issues and owns its own statuses and issue keys.
                    </p>
                    <Link href="/projects/create" className="mt-4 inline-block">
                        <Button size="sm">Create your first project</Button>
                    </Link>
                </div>
            ) : (
                <div className="overflow-hidden rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-surface text-left text-xs text-ink-muted">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">Key</th>
                                <th className="px-4 py-2.5 font-medium">Name</th>
                                <th className="px-4 py-2.5 font-medium">Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border bg-raised">
                            {projects.map((project) => (
                                <tr
                                    key={project.slug}
                                    className="transition hover:bg-surface"
                                >
                                    <td className="px-4 py-2.5">
                                        <span className="rounded bg-surface px-1.5 py-0.5 font-mono text-[11px] font-medium text-ink-muted">
                                            {project.key}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <Link
                                            href={`/projects/${project.slug}`}
                                            className="font-medium text-ink hover:text-accent"
                                        >
                                            {project.name}
                                        </Link>
                                        {project.is_archived && (
                                            <span className="ml-2 text-xs text-ink-subtle">
                                                Archived
                                            </span>
                                        )}
                                    </td>
                                    <td className="max-w-md truncate px-4 py-2.5 text-ink-muted">
                                        {project.description ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
