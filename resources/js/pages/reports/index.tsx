import { Button } from '@/components/button';
import { Diagnostics } from '@/components/diagnostics';
import { Avatar, ClientRepliedBadge, relativeTime } from '@/components/issue-bits';
import { Popover, PopoverItem } from '@/components/popover';
import { useHotkeys } from '@/hooks/use-hotkeys';
import { IssuePicker } from '@/components/issue-picker';
import { AppLayout } from '@/layouts/app-layout';
import type { Person, ReportRow, SharedProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    Inbox,
    Merge,
    Monitor,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

type Priority = { value: number; label: string; color: string };

/** An issue a client filed that is still in "New". */
type ClientIssue = {
    key: string;
    title: string;
    project: string;
    reporter: string | null;
    created_at: string;
    /** Set when a client has answered and nobody on the team has looked since. */
    replied_at: string | null;
};
type Type = { value: string; label: string };

/**
 * The triage inbox — the airlock between raw intake and the backlog.
 *
 * Built to be emptied: one keystroke per decision, and reports sharing a fingerprint
 * appear as one row so the same bug is not dismissed ten times.
 */
export default function ReportsIndex({
    reports,
    projects,
    filters,
    priorities,
    types,
    members,
    pending,
    clientIssues = [],
}: {
    reports: ReportRow[];
    projects: { id: number; name: string; key: string; slug: string }[];
    filters: { project: string | null };
    priorities: Priority[];
    types: Type[];
    members: Person[];
    pending: number;
    clientIssues?: ClientIssue[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const [active, setActive] = useState(0);
    const [expanded, setExpanded] = useState(false);
    const [merging, setMerging] = useState(false);

    const report = reports[active];

    const move = (delta: number) => {
        setActive((current) => Math.max(0, Math.min(reports.length - 1, current + delta)));
        setExpanded(false);
        setMerging(false);
    };

    function act(path: string, data: Record<string, unknown> = {}) {
        if (!report) return;

        router.post(
            `/inbox/${report.id}/${path}`,
            { ...data, also: report.ids },
            {
                preserveScroll: true,
                onSuccess: () => {
                    // The list shortens under us; stay on the same position.
                    setActive((current) => Math.max(0, Math.min(current, reports.length - 2)));
                    setExpanded(false);
                    setMerging(false);
                },
            },
        );
    }

    useHotkeys({
        j: () => move(1),
        ArrowDown: () => move(1),
        k: () => move(-1),
        ArrowUp: () => move(-1),
        Enter: () => setExpanded((e) => !e),
        a: () => act('accept'),
        m: () => {
            setMerging(true);
        },
        s: () => act('dismiss', { state: 'spam' }),
        x: () => act('dismiss', { state: 'discarded' }),
        Escape: () => {
            setMerging(false);
            setExpanded(false);
        },
    });

    return (
        <AppLayout
            title="Triage"
            actions={
                <Popover
                    align="right"
                    label="Filter by project"
                    trigger={() => (
                        <span className="rounded-lg border border-border px-2 py-1 text-xs text-ink-muted">
                            {projects.find((p) => p.slug === filters.project)?.key ?? 'All projects'}
                        </span>
                    )}
                >
                    {(close) => (
                        <>
                            <PopoverItem
                                selected={!filters.project}
                                onSelect={() => {
                                    close();
                                    router.get('/inbox');
                                }}
                            >
                                All projects
                            </PopoverItem>
                            {projects.map((project) => (
                                <PopoverItem
                                    key={project.id}
                                    selected={project.slug === filters.project}
                                    onSelect={() => {
                                        close();
                                        router.get('/inbox', { project: project.slug });
                                    }}
                                >
                                    <span className="font-mono text-[11px] text-ink-subtle">
                                        {project.key}
                                    </span>
                                    {project.name}
                                </PopoverItem>
                            ))}
                        </>
                    )}
                </Popover>
            }
        >
            <Head title={pending > 0 ? `Triage (${pending})` : 'Triage'} />

            {clientIssues.length > 0 && <RaisedByClients issues={clientIssues} />}

            {reports.length === 0 && clientIssues.length > 0 ? null : reports.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border-strong p-12 text-center">
                    <Inbox className="mx-auto size-6 text-ink-subtle" />
                    <p className="mt-3 text-sm font-medium text-ink">Inbox zero</p>
                    <p className="mt-1 text-sm text-ink-muted">
                        Nothing waiting. Incoming reports from the widget land here before
                        they reach the backlog.
                    </p>
                </div>
            ) : (
                <div className="grid gap-4 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
                    <ul className="max-h-[calc(100vh-11rem)] divide-y divide-border overflow-y-auto rounded-xl border border-border bg-raised">
                        {reports.map((row, index) => (
                            <li key={row.id}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setActive(index);
                                        setExpanded(false);
                                    }}
                                    className={`relative flex w-full gap-3 px-3 py-2.5 text-left transition ${
                                        index === active ? 'bg-surface' : 'hover:bg-surface'
                                    }`}
                                >
                                    {index === active && (
                                        <span
                                            aria-hidden
                                            className="absolute inset-y-0 left-0 w-0.5 bg-accent"
                                        />
                                    )}

                                    {row.screenshot_url ? (
                                        <img
                                            src={row.screenshot_url}
                                            alt=""
                                            className="size-11 shrink-0 rounded border border-border object-cover"
                                        />
                                    ) : (
                                        <span className="flex size-11 shrink-0 items-center justify-center rounded border border-border bg-surface">
                                            <Monitor className="size-4 text-ink-subtle" />
                                        </span>
                                    )}

                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-1.5">
                                            <span className="truncate text-sm text-ink">
                                                {row.title}
                                            </span>
                                            {row.count > 1 && (
                                                <span className="shrink-0 rounded-full bg-accent-soft px-1.5 py-0.5 text-[10px] font-semibold text-accent">
                                                    ×{row.count}
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-0.5 flex items-center gap-1.5 text-[11px] text-ink-subtle">
                                            <span className="font-mono">{row.project.key}</span>
                                            <span>·</span>
                                            <span className="truncate">
                                                {row.reporter.name ?? row.reporter.email ?? 'Anonymous'}
                                            </span>
                                            <span>·</span>
                                            <span>{relativeTime(row.created_at)}</span>
                                        </span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>

                    {report && (
                        <div className="max-h-[calc(100vh-11rem)] overflow-y-auto rounded-xl border border-border bg-raised p-4">
                            <h2 className="text-sm font-semibold text-ink">{report.title}</h2>

                            {report.count > 1 && (
                                <p className="mt-1 text-xs text-accent">
                                    {report.count} identical reports — acting here handles all of
                                    them.
                                </p>
                            )}

                            {report.body && (
                                <p className="mt-2 text-sm whitespace-pre-wrap text-ink-muted">
                                    {report.body}
                                </p>
                            )}

                            {report.screenshot_url && (
                                <img
                                    src={report.screenshot_url}
                                    alt="Reporter's screenshot"
                                    className="mt-3 w-full rounded-lg border border-border"
                                />
                            )}

                            <Diagnostics
                                data={report}
                                reporter={
                                    report.reporter.email ?? report.reporter.name ?? 'Anonymous'
                                }
                                expanded={expanded}
                                onExpandedChange={setExpanded}
                            />

                            <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                                <Button size="sm" onClick={() => act('accept')}>
                                    <Check className="size-4" />
                                    Accept
                                    <Key>a</Key>
                                </Button>

                                <Popover
                                    label="Accept with priority"
                                    trigger={() => (
                                        <span className="rounded-lg border border-border px-2 py-1.5 text-xs text-ink-muted">
                                            Accept as…
                                        </span>
                                    )}
                                >
                                    {(close) => (
                                        <>
                                            {priorities.map((priority) => (
                                                <PopoverItem
                                                    key={priority.value}
                                                    onSelect={() => {
                                                        close();
                                                        act('accept', { priority: priority.value });
                                                    }}
                                                >
                                                    {priority.label}
                                                </PopoverItem>
                                            ))}
                                            {types.map((type) => (
                                                <PopoverItem
                                                    key={type.value}
                                                    onSelect={() => {
                                                        close();
                                                        act('accept', { type: type.value });
                                                    }}
                                                >
                                                    As {type.label.toLowerCase()}
                                                </PopoverItem>
                                            ))}
                                            {members.map((member) => (
                                                <PopoverItem
                                                    key={member.id}
                                                    onSelect={() => {
                                                        close();
                                                        act('accept', { assignee_id: member.id });
                                                    }}
                                                >
                                                    <Avatar name={member.name} />
                                                    Assign to {member.name}
                                                </PopoverItem>
                                            ))}
                                        </>
                                    )}
                                </Popover>

                                <Button size="sm" variant="secondary" onClick={() => setMerging(true)}>
                                    <Merge className="size-4" />
                                    Merge
                                    <Key>m</Key>
                                </Button>

                                <IssuePicker
                                    open={merging}
                                    onClose={() => setMerging(false)}
                                    title="Merge this report into…"
                                    hint="It becomes another occurrence of the issue you choose, and leaves the inbox."
                                    project={report.project.slug}
                                    onPick={(issue) => act('merge', { key: issue.key })}
                                />

                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => act('dismiss', { state: 'spam' })}
                                >
                                    <ShieldAlert className="size-4" />
                                    Spam
                                    <Key>s</Key>
                                </Button>

                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => act('dismiss', { state: 'discarded' })}
                                >
                                    <Trash2 className="size-4" />
                                    Discard
                                    <Key>x</Key>
                                </Button>

                                <span className="ml-auto text-[11px] text-ink-subtle">
                                    j / k to move
                                </span>
                            </div>

                            {auth.role === 'client' && null}
                        </div>
                    )}
                </div>
            )}
        </AppLayout>
    );
}


/**
 * Issues clients filed, waiting in "New". Already issues, so they open on their own
 * page; moving one to any other status is what takes it off this list.
 */
function RaisedByClients({ issues }: { issues: ClientIssue[] }) {
    return (
        <section className="mb-6">
            <h2 className="text-sm font-semibold text-ink">From clients</h2>
            <p className="mt-0.5 text-xs text-ink-muted">
                New issues waiting in New, and replies nobody on the team has opened yet.
                Moving a new one on, or opening a reply, takes it off this list.
            </p>
            <ul className="mt-3 divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                {issues.map((issue) => (
                    <li key={issue.key}>
                        <Link
                            href={`/issues/${issue.key}`}
                            className="flex items-center gap-3 px-4 py-2.5 transition hover:bg-surface"
                        >
                            <span className="shrink-0 font-mono text-[11px] text-ink-subtle">
                                {issue.key}
                            </span>
                            <span className="min-w-0 flex-1 truncate text-sm text-ink">
                                {issue.title}
                            </span>
                            {issue.replied_at && <ClientRepliedBadge />}
                            <span className="shrink-0 text-xs text-ink-subtle">
                                {issue.reporter ?? 'A client'} · {relativeTime(issue.created_at)}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Key({ children }: { children: string }) {
    return (
        <kbd className="ml-1 rounded border border-current/30 px-1 font-mono text-[9px] opacity-70">
            {children}
        </kbd>
    );
}
