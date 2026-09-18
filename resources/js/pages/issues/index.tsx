import { Button } from '@/components/button';
import { StatusDot } from '@/components/issue-bits';
import { IssueRow } from '@/components/issue-row';
import { Popover, PopoverItem } from '@/components/popover';
import { AppLayout } from '@/layouts/app-layout';
import type { Facets, IssueFilters, IssueRow as Row, SharedProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, Plus, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

type FilterValue = string | number | null;

function useFilterNav(filters: IssueFilters) {
    return (changes: Record<string, FilterValue>) => {
        const next: Record<string, string> = {};

        for (const [key, value] of Object.entries({ ...filters, ...changes })) {
            if (value !== null && value !== '' && !(key === 'state' && value === 'open')) {
                next[key] = String(value);
            }
        }

        router.get('/issues', next, { preserveState: true, preserveScroll: true });
    };
}

function Chip({
    label,
    value,
    onClear,
    children,
}: {
    label: string;
    value: string | null;
    onClear?: () => void;
    children: (close: () => void) => React.ReactNode;
}) {
    return (
        <span className="flex items-center">
            <Popover
                label={label}
                trigger={() => (
                    <span
                        className={`flex items-center gap-1.5 rounded-lg border px-2 py-1 text-xs transition ${
                            value
                                ? 'border-accent/40 bg-accent-soft text-accent'
                                : 'border-border text-ink-muted hover:text-ink'
                        }`}
                    >
                        {label}
                        {value && <span className="font-medium">{value}</span>}
                    </span>
                )}
            >
                {children}
            </Popover>
            {value && onClear && (
                <button
                    type="button"
                    onClick={onClear}
                    aria-label={`Clear ${label} filter`}
                    className="-ml-1 rounded p-1 text-accent transition hover:bg-surface"
                >
                    <X className="size-3" />
                </button>
            )}
        </span>
    );
}

export default function IssuesIndex({
    issues,
    filters,
    facets,
}: {
    issues: Row[];
    filters: IssueFilters;
    facets: Facets;
}) {
    const { auth } = usePage<SharedProps>().props;
    const editable = auth.role !== 'client';
    const navigate = useFilterNav(filters);

    const [query, setQuery] = useState(filters.q ?? '');
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

    // Group by status name. Statuses are per project, so two projects' "In Progress"
    // columns merge into one group — which is what you want in a cross-project list.
    const groups = useMemo(() => {
        const map = new Map<string, { status: Row['status']; issues: Row[] }>();

        for (const issue of issues) {
            const existing = map.get(issue.status.name);
            if (existing) {
                existing.issues.push(issue);
            } else {
                map.set(issue.status.name, { status: issue.status, issues: [issue] });
            }
        }

        return [...map.values()].sort(
            (a, b) => Number(b.status.open) - Number(a.status.open),
        );
    }, [issues]);

    const selectedProject = facets.projects.find((p) => p.slug === filters.project);
    const selectedLabel = facets.labels.find((l) => l.id === filters.label);
    const selectedAssignee =
        filters.assignee === 'none'
            ? 'Unassigned'
            : facets.members.find((m) => String(m.id) === filters.assignee)?.name ?? null;

    return (
        <AppLayout
            title="Issues"
            actions={
                <Link href={`/issues/create${filters.project ? `?project=${filters.project}` : ''}`}>
                    <Button size="sm">
                        <Plus className="size-4" />
                        New issue
                    </Button>
                </Link>
            }
        >
            <Head title="Issues" />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        navigate({ q: query || null });
                    }}
                    className="relative"
                >
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-subtle" />
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search or type an issue key…"
                        aria-label="Search issues"
                        className="h-[30px] w-64 rounded-lg border border-border bg-raised pr-2 pl-8 text-xs text-ink placeholder:text-ink-subtle focus:border-accent focus:outline-none"
                    />
                </form>

                <Chip
                    label="Status"
                    value={filters.state === 'open' ? null : filters.state}
                    onClear={() => navigate({ state: 'open' })}
                >
                    {(close) =>
                        (['open', 'closed', 'all'] as const).map((state) => (
                            <PopoverItem
                                key={state}
                                selected={filters.state === state}
                                onSelect={() => {
                                    close();
                                    navigate({ state });
                                }}
                            >
                                <span className="capitalize">{state}</span>
                            </PopoverItem>
                        ))
                    }
                </Chip>

                <Chip
                    label="Project"
                    value={selectedProject?.key ?? null}
                    onClear={() => navigate({ project: null })}
                >
                    {(close) =>
                        facets.projects.map((project) => (
                            <PopoverItem
                                key={project.id}
                                selected={project.slug === filters.project}
                                onSelect={() => {
                                    close();
                                    navigate({ project: project.slug });
                                }}
                            >
                                <span className="font-mono text-[11px] text-ink-subtle">
                                    {project.key}
                                </span>
                                <span className="truncate">{project.name}</span>
                            </PopoverItem>
                        ))
                    }
                </Chip>

                <Chip
                    label="Assignee"
                    value={selectedAssignee}
                    onClear={() => navigate({ assignee: null })}
                >
                    {(close) => (
                        <>
                            <PopoverItem
                                selected={filters.assignee === 'me'}
                                onSelect={() => {
                                    close();
                                    navigate({ assignee: 'me' });
                                }}
                            >
                                Assigned to me
                            </PopoverItem>
                            <PopoverItem
                                selected={filters.assignee === 'none'}
                                onSelect={() => {
                                    close();
                                    navigate({ assignee: 'none' });
                                }}
                            >
                                Unassigned
                            </PopoverItem>
                            {facets.members.map((member) => (
                                <PopoverItem
                                    key={member.id}
                                    selected={filters.assignee === String(member.id)}
                                    onSelect={() => {
                                        close();
                                        navigate({ assignee: member.id });
                                    }}
                                >
                                    <span className="truncate">{member.name}</span>
                                </PopoverItem>
                            ))}
                        </>
                    )}
                </Chip>

                <Chip
                    label="Label"
                    value={selectedLabel?.name ?? null}
                    onClear={() => navigate({ label: null })}
                >
                    {(close) =>
                        facets.labels.length === 0 ? (
                            <p className="px-2 py-1.5 text-xs text-ink-subtle">
                                No labels yet.
                            </p>
                        ) : (
                            facets.labels.map((label) => (
                                <PopoverItem
                                    key={label.id}
                                    selected={label.id === filters.label}
                                    onSelect={() => {
                                        close();
                                        navigate({ label: label.id });
                                    }}
                                >
                                    <span
                                        aria-hidden
                                        className="size-2 rounded-full"
                                        style={{ backgroundColor: label.color }}
                                    />
                                    <span className="truncate">{label.name}</span>
                                </PopoverItem>
                            ))
                        )
                    }
                </Chip>

                <span className="ml-auto text-xs text-ink-subtle">
                    {issues.length} issue{issues.length === 1 ? '' : 's'}
                </span>
            </div>

            {issues.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border-strong p-12 text-center">
                    <p className="text-sm font-medium text-ink">Nothing here</p>
                    <p className="mt-1 text-sm text-ink-muted">
                        {filters.q || filters.project || filters.assignee || filters.label
                            ? 'No issues match these filters.'
                            : 'No open issues. Enjoy it while it lasts.'}
                    </p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-xl border border-border bg-raised">
                    {groups.map(({ status, issues: rows }) => (
                        <section key={status.name}>
                            <button
                                type="button"
                                onClick={() =>
                                    setCollapsed((c) => ({
                                        ...c,
                                        [status.name]: !c[status.name],
                                    }))
                                }
                                aria-expanded={!collapsed[status.name]}
                                className="flex w-full items-center gap-2 border-b border-border bg-surface px-4 py-2 text-left"
                            >
                                <ChevronRight
                                    className={`size-3.5 text-ink-subtle transition-transform ${
                                        collapsed[status.name] ? '' : 'rotate-90'
                                    }`}
                                />
                                <StatusDot status={status} />
                                <span className="text-xs font-medium text-ink">
                                    {status.name}
                                </span>
                                <span className="text-xs text-ink-subtle">{rows.length}</span>
                            </button>

                            {!collapsed[status.name] && (
                                <div className="divide-y divide-border">
                                    {rows.map((issue) => (
                                        <IssueRow
                                            key={issue.id}
                                            issue={issue}
                                            statuses={
                                                facets.statuses_by_project[issue.project.id] ?? []
                                            }
                                            facets={facets}
                                            editable={editable}
                                            showProject={!filters.project}
                                        />
                                    ))}
                                </div>
                            )}
                        </section>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
