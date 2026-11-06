import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import { CategoryLegend } from '@/components/category-legend';
import { Input } from '@/components/field';
import type { ProjectCategory, ProjectSummary } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

const palette = [
    '#ef4444', '#f59e0b', '#10b981', '#3b82f6',
    '#8b5cf6', '#ec4899', '#64748b', '#0ea5e9',
];

/**
 * The workspace's project categories: a name and a colour each. Admins only; everybody
 * else just sees the colours.
 */
function CategoryManager({ categories }: { categories: ProjectCategory[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', color: palette[3] });

    return (
        <section className="mt-8 max-w-2xl">
            <h2 className="text-sm font-semibold text-ink">Categories</h2>
            <p className="mt-0.5 text-xs text-ink-muted">
                Name the kinds of work you do and give each a colour. They colour the dashboard and
                become its legend and filter. Set a project's category in its settings.
            </p>

            <ul className="mt-3 divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                {categories.map((category) => (
                    <li key={category.id} className="flex items-center gap-3 px-4 py-2">
                        <input
                            type="color"
                            value={category.color}
                            aria-label={`Colour for ${category.name}`}
                            onChange={(e) =>
                                router.patch(
                                    `/project-categories/${category.id}`,
                                    { name: category.name, color: e.target.value },
                                    { preserveScroll: true },
                                )
                            }
                            className="size-6 cursor-pointer rounded border-0 bg-transparent p-0"
                        />
                        <span className="min-w-0 flex-1 truncate text-sm text-ink">{category.name}</span>
                        <button
                            type="button"
                            aria-label={`Delete ${category.name}`}
                            onClick={() => router.delete(`/project-categories/${category.id}`, { preserveScroll: true })}
                            className="rounded p-1 text-ink-subtle transition hover:text-danger"
                        >
                            <Trash2 className="size-3.5" />
                        </button>
                    </li>
                ))}
            </ul>

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/project-categories', { preserveScroll: true, onSuccess: () => reset('name') });
                }}
                className="mt-3 flex flex-wrap items-center gap-2"
            >
                <Input
                    value={data.name}
                    placeholder="Design, Development, Consultancy…"
                    onChange={(e) => setData('name', e.target.value)}
                    className="min-w-48 flex-1"
                />
                <div className="flex items-center gap-1">
                    {palette.map((color) => (
                        <button
                            key={color}
                            type="button"
                            aria-label={`Colour ${color}`}
                            aria-pressed={data.color === color}
                            onClick={() => setData('color', color)}
                            className={`size-6 rounded-full transition ${
                                data.color === color ? 'ring-2 ring-accent ring-offset-2 ring-offset-canvas' : ''
                            }`}
                            style={{ backgroundColor: color }}
                        />
                    ))}
                </div>
                <Button type="submit" size="sm" disabled={processing || !data.name}>
                    Add category
                </Button>
                {errors.name && <p className="w-full text-xs text-danger">{errors.name}</p>}
            </form>
        </section>
    );
}

export default function ProjectsIndex({
    projects,
    categories = [],
    can_manage = false,
}: {
    projects: ProjectSummary[];
    categories?: ProjectCategory[];
    can_manage?: boolean;
}) {
    const [filter, setFilter] = useState<number[]>([]);
    const shown = filter.length ? projects.filter((p) => filter.includes(p.category?.id ?? 0)) : projects;

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
                <>
                <div className="mb-3">
                    <CategoryLegend
                        categories={categories}
                        selected={filter}
                        onChange={setFilter}
                        hasUncategorised={projects.some((p) => !p.category)}
                    />
                </div>
                <div className="overflow-hidden rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-surface text-left text-xs text-ink-muted">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">Key</th>
                                <th className="px-4 py-2.5 font-medium">Name</th>
                                <th className="px-4 py-2.5 font-medium">Category</th>
                                <th className="px-4 py-2.5 font-medium">Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border bg-raised">
                            {shown.map((project) => (
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
                                    <td className="px-4 py-2.5">
                                        {project.category ? (
                                            <span className="flex items-center gap-1.5 text-xs text-ink-muted">
                                                <span
                                                    aria-hidden
                                                    className="size-2.5 rounded-full"
                                                    style={{ backgroundColor: project.category.color }}
                                                />
                                                {project.category.name}
                                            </span>
                                        ) : (
                                            <span className="text-ink-subtle">—</span>
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
                </>
            )}

            {can_manage && <CategoryManager categories={categories} />}
        </AppLayout>
    );
}
