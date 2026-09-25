import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import type { ProjectSummary, Status, StatusCategory } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Settings, Upload } from 'lucide-react';

interface Category {
    value: StatusCategory;
    label: string;
    open: boolean;
}

export default function ShowProject({
    project,
    statuses,
    categories,
}: {
    project: ProjectSummary & { issue_sequence: number; created_at: string };
    statuses: Status[];
    categories: Category[];
}) {
    return (
        <AppLayout
            title={project.name}
            actions={
                <div className="flex gap-2">
                    <Link href={`/projects/${project.slug}/import`}>
                        <Button variant="secondary" size="sm">
                            <Upload className="size-4" />
                            Import issues
                        </Button>
                    </Link>
                    <Link href={`/projects/${project.slug}/edit`}>
                        <Button variant="secondary" size="sm">
                            <Settings className="size-4" />
                            Settings
                        </Button>
                    </Link>
                </div>
            }
        >
            <Head title={project.name} />

            <div className="flex items-center gap-2">
                <span className="rounded bg-surface px-1.5 py-0.5 font-mono text-[11px] font-medium text-ink-muted">
                    {project.key}
                </span>
                <span className="text-xs text-ink-subtle">
                    Next issue will be {project.key}-{project.issue_sequence + 1}
                </span>
            </div>

            {project.description && (
                <p className="mt-3 max-w-2xl text-sm text-pretty text-ink-muted">
                    {project.description}
                </p>
            )}

            <section className="mt-8 max-w-2xl">
                <h2 className="text-sm font-semibold text-ink">Workflow</h2>
                <p className="mt-1 text-sm text-pretty text-ink-muted">
                    Statuses are yours to rename. Each one maps to a fixed category, which
                    is what lets Buggie answer "is this issue still open?" without guessing.
                </p>

                <ul className="mt-4 divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                    {statuses.map((status) => (
                        <li
                            key={status.id}
                            className="flex items-center gap-3 px-4 py-2.5"
                        >
                            <span
                                aria-hidden
                                className="size-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: status.color }}
                            />
                            <span className="flex-1 text-sm text-ink">{status.name}</span>
                            {status.is_default && (
                                <span className="rounded bg-accent-soft px-1.5 py-0.5 text-[11px] font-medium text-accent">
                                    Default
                                </span>
                            )}
                            <span className="w-20 text-right text-xs text-ink-subtle">
                                {status.category}
                            </span>
                            <span
                                className={`w-14 text-right text-xs ${
                                    status.open ? 'text-ink-muted' : 'text-ink-subtle'
                                }`}
                            >
                                {status.open ? 'Open' : 'Closed'}
                            </span>
                        </li>
                    ))}
                </ul>

                <p className="mt-2 text-xs text-ink-subtle">
                    Categories: {categories.map((c) => c.label).join(' · ')}
                </p>
            </section>
        </AppLayout>
    );
}
