import { StatusDot, TypeIcon } from '@/components/issue-bits';
import type { Facets, IssueRow, SavedView, SharedProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Command } from 'cmdk';
import {
    Bookmark,
    CircleDot,
    FolderKanban,
    Inbox,
    LayoutGrid,
    List,
    Moon,
    Plus,
    Search,
    Sun,
    Tag,
} from 'lucide-react';
import { useEffect, useState } from 'react';

function toggleTheme() {
    const root = document.documentElement;
    const dark = root.classList.toggle('dark');

    try {
        localStorage.setItem('buggie.theme', dark ? 'dark' : 'light');
    } catch {
        // Private windows and blocked site data: the toggle still works for this page.
    }
}

export function CommandPalette({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    // Whatever the current page happens to expose; all of it is optional.
    const page = usePage<SharedProps & { issues?: IssueRow[]; facets?: Facets }>().props;
    const issues = page.issues ?? [];
    const facets = page.facets;
    const views: SavedView[] = page.views ?? [];

    const [search, setSearch] = useState('');

    useEffect(() => {
        if (!open) setSearch('');
    }, [open]);

    function go(url: string) {
        onOpenChange(false);
        router.visit(url);
    }

    // Typing an issue key should offer it even when it is not in the loaded list.
    const keyMatch = /^[a-z][a-z0-9]*-\d+$/i.test(search.trim())
        ? search.trim().toUpperCase()
        : null;

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-[12vh]"
            onClick={() => onOpenChange(false)}
        >
            <Command
                label="Command palette"
                onClick={(e) => e.stopPropagation()}
                className="w-full max-w-lg overflow-hidden rounded-xl border border-border bg-raised shadow-2xl"
                // cmdk filters on its own; we only supply the items.
                loop
            >
                <div className="flex items-center gap-2 border-b border-border px-3">
                    <Search className="size-4 shrink-0 text-ink-subtle" />
                    <Command.Input
                        value={search}
                        onValueChange={setSearch}
                        autoFocus
                        placeholder="Search issues, jump to a view, run a command…"
                        className="h-11 flex-1 bg-transparent text-sm text-ink placeholder:text-ink-subtle focus:outline-none"
                    />
                    <kbd className="rounded border border-border px-1.5 py-0.5 text-[10px] text-ink-subtle">
                        esc
                    </kbd>
                </div>

                <Command.List className="max-h-80 overflow-y-auto p-1.5">
                    <Command.Empty className="px-3 py-6 text-center text-sm text-ink-muted">
                        Nothing matches “{search}”.
                    </Command.Empty>

                    {keyMatch && (
                        <Command.Group heading="Jump to">
                            <Item onSelect={() => go(`/issues/${keyMatch}`)}>
                                <CircleDot className="size-4 text-ink-subtle" />
                                Open <span className="font-mono">{keyMatch}</span>
                            </Item>
                        </Command.Group>
                    )}

                    {issues.length > 0 && (
                        <Command.Group heading="Issues" className="cmdk-group">
                            {issues.slice(0, 50).map((issue) => (
                                <Item
                                    key={issue.id}
                                    value={`${issue.key} ${issue.title}`}
                                    onSelect={() => go(`/issues/${issue.key}`)}
                                >
                                    <StatusDot status={issue.status} />
                                    <TypeIcon type={issue.type} />
                                    <span className="w-16 shrink-0 font-mono text-xs text-ink-subtle">
                                        {issue.key}
                                    </span>
                                    <span className="truncate">{issue.title}</span>
                                </Item>
                            ))}
                        </Command.Group>
                    )}

                    {views.length > 0 && (
                        <Command.Group heading="Views">
                            {views.map((view) => (
                                <Item
                                    key={view.id}
                                    value={`view ${view.name}`}
                                    onSelect={() =>
                                        go(
                                            `/issues?q=${encodeURIComponent(view.query)}&layout=${view.layout}&group=${view.group_by}`,
                                        )
                                    }
                                >
                                    <Bookmark className="size-4 text-ink-subtle" />
                                    {view.name}
                                </Item>
                            ))}
                        </Command.Group>
                    )}

                    {facets && facets.projects.length > 0 && (
                        <Command.Group heading="Projects">
                            {facets.projects.map((project) => (
                                <Item
                                    key={project.id}
                                    value={`project ${project.key} ${project.name}`}
                                    onSelect={() =>
                                        go(`/issues?q=${encodeURIComponent(`project:${project.slug}`)}`)
                                    }
                                >
                                    <FolderKanban className="size-4 text-ink-subtle" />
                                    <span className="w-10 shrink-0 font-mono text-xs text-ink-subtle">
                                        {project.key}
                                    </span>
                                    {project.name}
                                </Item>
                            ))}
                        </Command.Group>
                    )}

                    <Command.Group heading="Commands">
                        <Item value="new issue create" onSelect={() => go('/issues/create')}>
                            <Plus className="size-4 text-ink-subtle" />
                            New issue
                            <Shortcut keys="c" />
                        </Item>
                        <Item value="assigned to me" onSelect={() => go('/issues?q=is%3Aopen+assignee%3A%40me')}>
                            <CircleDot className="size-4 text-ink-subtle" />
                            Issues assigned to me
                        </Item>
                        <Item value="unassigned triage" onSelect={() => go('/issues?q=is%3Aopen+no%3Aassignee')}>
                            <CircleDot className="size-4 text-ink-subtle" />
                            Unassigned issues
                        </Item>
                        <Item value="list layout" onSelect={() => go('/issues?layout=list')}>
                            <List className="size-4 text-ink-subtle" />
                            Switch to list
                            <Shortcut keys="g l" />
                        </Item>
                        <Item value="board layout kanban" onSelect={() => go('/issues?layout=board')}>
                            <LayoutGrid className="size-4 text-ink-subtle" />
                            Switch to board
                            <Shortcut keys="g b" />
                        </Item>
                        <Item value="triage inbox reports" onSelect={() => go('/inbox')}>
                            <Inbox className="size-4 text-ink-subtle" />
                            Triage inbox
                            <Shortcut keys="g t" />
                        </Item>
                        <Item value="labels" onSelect={() => go('/labels')}>
                            <Tag className="size-4 text-ink-subtle" />
                            Manage labels
                        </Item>
                        <Item
                            value="theme dark light appearance"
                            onSelect={() => {
                                toggleTheme();
                                onOpenChange(false);
                            }}
                        >
                            <Sun className="size-4 text-ink-subtle dark:hidden" />
                            <Moon className="hidden size-4 text-ink-subtle dark:block" />
                            Toggle theme
                        </Item>
                    </Command.Group>
                </Command.List>
            </Command>
        </div>
    );
}

function Item({
    children,
    onSelect,
    value,
}: {
    children: React.ReactNode;
    onSelect: () => void;
    value?: string;
}) {
    return (
        <Command.Item
            value={value}
            onSelect={onSelect}
            className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-ink-muted data-[selected=true]:bg-surface data-[selected=true]:text-ink"
        >
            {children}
        </Command.Item>
    );
}

function Shortcut({ keys }: { keys: string }) {
    return (
        <span className="ml-auto flex gap-1">
            {keys.split(' ').map((key) => (
                <kbd
                    key={key}
                    className="rounded border border-border px-1.5 py-0.5 font-mono text-[10px] text-ink-subtle"
                >
                    {key}
                </kbd>
            ))}
        </span>
    );
}
