import { Command } from 'cmdk';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface PickedIssue {
    key: string;
    title: string;
    project: string;
    status: string;
    open: boolean;
}

/**
 * Choose an issue by what it is about, not by its key.
 *
 * Linking, marking a duplicate and merging a report all used to ask for a key typed
 * from memory, and nobody remembers keys. This opens with recent work from the same
 * project and narrows as they type a few words of the title, or a key if they
 * happen to know one. The server does the matching (see IssueLookupController);
 * this only shows the answer and handles the keyboard.
 */
export function IssuePicker({
    open,
    onClose,
    onPick,
    title,
    hint,
    project,
    exclude,
    error,
}: {
    open: boolean;
    onClose: () => void;
    onPick: (issue: PickedIssue) => void;
    title: string;
    /** A line under the title saying what picking will do. */
    hint?: string;
    /** A project slug whose issues are suggested first. */
    project?: string;
    /** The issue doing the picking, left out of the list. */
    exclude?: string;
    /** The server's refusal of the last pick, shown in place. */
    error?: string;
}) {
    const [search, setSearch] = useState('');
    const [issues, setIssues] = useState<PickedIssue[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) {
            setSearch('');
            setIssues([]);
            return;
        }

        const controller = new AbortController();
        // A short pause, so a word typed quickly is one request rather than five.
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams();
            if (search.trim()) params.set('q', search.trim());
            if (project) params.set('project', project);
            if (exclude) params.set('exclude', exclude);

            setLoading(true);
            fetch(`/issues-lookup?${params}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                .then((body: { issues: PickedIssue[] }) => {
                    setIssues(body.issues);
                    setFailed(false);
                })
                .catch(() => controller.signal.aborted || setFailed(true))
                .finally(() => controller.signal.aborted || setLoading(false));
        }, 150);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, search, project, exclude]);

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-[12vh]"
            onClick={onClose}
        >
            <Command
                label={title}
                shouldFilter={false}
                loop
                onClick={(e) => e.stopPropagation()}
                onKeyDown={(e) => e.key === 'Escape' && onClose()}
                className="w-full max-w-lg overflow-hidden rounded-xl border border-border bg-raised shadow-2xl"
            >
                <div className="border-b border-border px-4 pt-3 pb-2">
                    <p className="text-sm font-medium text-ink">{title}</p>
                    {hint && <p className="mt-0.5 text-xs text-ink-subtle">{hint}</p>}
                </div>

                <div className="flex items-center gap-2 border-b border-border px-3">
                    <Search className="size-4 shrink-0 text-ink-subtle" />
                    <Command.Input
                        value={search}
                        onValueChange={setSearch}
                        autoFocus
                        placeholder="Type a few words of the title, or a key…"
                        className="h-11 w-full bg-transparent text-sm text-ink outline-none placeholder:text-ink-subtle"
                    />
                </div>

                {error && <p className="border-b border-border px-4 py-2 text-xs text-danger">{error}</p>}

                <Command.List className="max-h-80 overflow-y-auto p-1.5">
                    {!loading && (
                        <Command.Empty className="px-3 py-6 text-center text-sm text-ink-subtle">
                            {failed ? 'Could not load issues. Try again.' : search ? `Nothing matches “${search}”.` : 'No issues yet.'}
                        </Command.Empty>
                    )}

                    {!search && issues.length > 0 && (
                        <p className="px-2.5 pt-1 pb-1.5 text-[11px] text-ink-subtle">Recent, open first</p>
                    )}

                    {issues.map((issue) => (
                        <Command.Item
                            key={issue.key}
                            value={issue.key}
                            onSelect={() => onPick(issue)}
                            className="flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-ink data-[selected=true]:bg-accent-soft"
                        >
                            <span className="w-16 shrink-0 font-mono text-xs text-ink-muted">{issue.key}</span>
                            <span className={`min-w-0 flex-1 truncate ${issue.open ? '' : 'text-ink-subtle line-through'}`}>
                                {issue.title}
                            </span>
                            <span className="shrink-0 text-[11px] text-ink-subtle">{issue.project}</span>
                            <span className="shrink-0 text-[11px] text-ink-subtle">{issue.status}</span>
                        </Command.Item>
                    ))}
                </Command.List>
            </Command>
        </div>
    );
}
