import { Attachments, type AttachmentRow } from '@/components/attachments';
import { Button } from '@/components/button';
import {
    Avatar,
    LabelPill,
    PriorityBars,
    StatusDot,
    TypeIcon,
    relativeTime,
} from '@/components/issue-bits';
import { Popover, PopoverItem } from '@/components/popover';
import { RichTextEditor, RichTextView } from '@/components/rich-text';
import { Diagnostics, type DiagnosticsData } from '@/components/diagnostics';
import { AppLayout } from '@/layouts/app-layout';
import type {
    Facets,
    IssueRow,
    IssueStatus,
    LabelChip,
    Person,
    SharedProps,
    VisibilityValue,
} from '@/types';
import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { JSONContent } from '@tiptap/react';
import { Eye, EyeOff, Lock, Tag, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Comment {
    id: number;
    body: JSONContent;
    is_internal: boolean;
    author: Person | null;
    created_at: string;
    edited_at: string | null;
    can_edit: boolean;
}

interface Event {
    id: number;
    type: string;
    data: Record<string, unknown>;
    actor: Person | null;
    created_at: string;
}

interface Issue extends IssueRow {
    description: JSONContent | null;
    reporter: Person | null;
    visibility: VisibilityValue;
    due_on: string | null;
    created_at: string;
    watchers: Person[];
    relations: {
        id: number;
        type: string;
        label: string;
        issue: { key: string; title: string; status: string; open: boolean };
    }[];
}

/** Renders one activity event as a sentence. */
function eventSentence(event: Event): string {
    const d = event.data as Record<string, { name?: string } | string | number | null>;
    const actor = event.actor?.name ?? 'Someone';

    switch (event.type) {
        case 'created':
            return `${actor} created this issue`;
        case 'status_changed':
            return `${actor} moved this from ${(d.from as { name: string })?.name} to ${(d.to as { name: string })?.name}`;
        case 'assigned':
            return `${actor} assigned this to ${d.to as string}`;
        case 'unassigned':
            return `${actor} unassigned this`;
        case 'priority_changed':
            return `${actor} changed the priority`;
        case 'type_changed':
            return `${actor} changed the type to ${d.to as string}`;
        case 'title_changed':
            return `${actor} renamed this issue`;
        case 'label_added':
            return `${actor} added the label ${d.name as string}`;
        case 'label_removed':
            return `${actor} removed the label ${d.name as string}`;
        case 'visibility_changed':
            return d.to === 'client'
                ? `${actor} made this visible to the client`
                : `${actor} made this internal only`;
        case 'closed':
            return `${actor} closed this issue`;
        case 'reopened':
            return `${actor} reopened this issue`;
        case 'attachment_added':
            return `${actor} attached ${d.filename as string}`;
        case 'occurrence':
            return `${actor === 'Someone' ? 'Reported again' : `${actor} recorded another occurrence`}` +
                (d.count ? ` (${d.count} in total)` : '');
        case 'related':
            return `${actor} linked ${d.key as string}`;
        case 'unrelated':
            return `${actor} unlinked ${d.key as string}`;
        default:
            return `${actor} updated this issue`;
    }
}

function SidebarRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start gap-3 py-2">
            <span className="w-20 shrink-0 pt-1 text-xs text-ink-subtle">{label}</span>
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}

export default function ShowIssue({
    issue,
    comments,
    events,
    attachments,
    statuses,
    facets,
    can,
    diagnostics,
}: {
    issue: Issue;
    comments: Comment[];
    events: Event[];
    attachments: AttachmentRow[];
    statuses: IssueStatus[];
    facets: Facets;
    /** Null for clients, and for issues with no captured context. */
    diagnostics: DiagnosticsData | null;
    can: {
        update: boolean;
        comment_internally: boolean;
        delete: boolean;
        attach: boolean;
    };
}) {
    const { auth } = usePage<SharedProps>().props;

    const [body, setBody] = useState<JSONContent | null>(null);
    const [internal, setInternal] = useState(can.comment_internally);
    const [editingTitle, setEditingTitle] = useState(false);
    const [title, setTitle] = useState(issue.title);
    const [description, setDescription] = useState<JSONContent | null>(issue.description);
    const [editingDescription, setEditingDescription] = useState(false);

    function patch(payload: RequestPayload) {
        router.patch(`/issues/${issue.key}`, payload, {
            preserveScroll: true,
            only: ['issue', 'events', 'flash'],
        });
    }

    function submitComment() {
        if (!body) return;

        router.post(
            `/issues/${issue.key}/comments`,
            { body, is_internal: internal },
            {
                preserveScroll: true,
                only: ['comments', 'events', 'flash', 'errors'],
                onSuccess: () => setBody(null),
            },
        );
    }

    // Comments and events interleave into one chronological stream.
    const stream = [
        ...comments.map((c) => ({ kind: 'comment' as const, at: c.created_at, data: c })),
        ...events.map((e) => ({ kind: 'event' as const, at: e.created_at, data: e })),
    ].sort((a, b) => a.at.localeCompare(b.at));

    return (
        <AppLayout
            title={issue.key}
            actions={
                can.delete && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.delete(`/issues/${issue.key}`)}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                )
            }
        >
            <Head title={`${issue.key} · ${issue.title}`} />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 text-xs text-ink-subtle">
                        <Link
                            href={`/issues?q=${encodeURIComponent(`project:${issue.project.slug}`)}`}
                            className="hover:text-ink"
                        >
                            {issue.project.name}
                        </Link>
                        <span>/</span>
                        <span className="font-mono">{issue.key}</span>
                        {issue.visibility === 'client' && (
                            <span className="ml-1 flex items-center gap-1 rounded bg-accent-soft px-1.5 py-0.5 text-accent">
                                <Eye className="size-3" />
                                Client can see this
                            </span>
                        )}
                    </div>

                    {editingTitle && can.update ? (
                        <input
                            value={title}
                            autoFocus
                            onChange={(e) => setTitle(e.target.value)}
                            onBlur={() => {
                                setEditingTitle(false);
                                if (title !== issue.title) patch({ title });
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') e.currentTarget.blur();
                                if (e.key === 'Escape') {
                                    setTitle(issue.title);
                                    setEditingTitle(false);
                                }
                            }}
                            className="mt-2 w-full rounded-lg border border-accent bg-raised px-2 py-1 text-xl font-semibold text-ink focus:outline-none"
                        />
                    ) : (
                        <h1
                            onClick={() => can.update && setEditingTitle(true)}
                            className={`mt-2 text-xl font-semibold tracking-tight text-balance text-ink ${
                                can.update ? 'cursor-text rounded px-2 py-1 -mx-2 hover:bg-surface' : ''
                            }`}
                        >
                            {issue.title}
                        </h1>
                    )}

                    <section className="mt-4">
                        {editingDescription && can.update ? (
                            <div className="space-y-2">
                                <RichTextEditor
                                    value={description}
                                    onChange={setDescription}
                                    autoFocus
                                    placeholder="What happens, and what should happen instead?"
                                    onSubmit={() => {
                                        patch({ description });
                                        setEditingDescription(false);
                                    }}
                                />
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() => {
                                            patch({ description });
                                            setEditingDescription(false);
                                        }}
                                    >
                                        Save
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => {
                                            setDescription(issue.description);
                                            setEditingDescription(false);
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <div
                                onClick={() => can.update && setEditingDescription(true)}
                                className={`rounded-lg ${can.update ? 'cursor-text px-2 py-1 -mx-2 hover:bg-surface' : ''}`}
                            >
                                {issue.description ? (
                                    <RichTextView value={issue.description} />
                                ) : (
                                    <p className="text-sm text-ink-subtle">
                                        {can.update
                                            ? 'Add a description…'
                                            : 'No description.'}
                                    </p>
                                )}
                            </div>
                        )}
                    </section>

                    {(attachments.length > 0 || can.attach) && (
                        <div className="mt-6">
                            <Attachments
                                issueKey={issue.key}
                                attachments={attachments}
                                canUpload={can.attach}
                                canDelete={can.update}
                            />
                        </div>
                    )}

                    <section className="mt-8">
                        <h2 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                            Activity
                        </h2>

                        <ol className="mt-3 space-y-3">
                            {stream.map((entry) =>
                                entry.kind === 'event' ? (
                                    <li
                                        key={`e${entry.data.id}`}
                                        className="flex items-center gap-2 text-xs text-ink-muted"
                                    >
                                        <span className="ml-2 size-1.5 rounded-full bg-border-strong" />
                                        {eventSentence(entry.data)}
                                        <span className="text-ink-subtle">
                                            · {relativeTime(entry.at)}
                                        </span>
                                    </li>
                                ) : (
                                    <li
                                        key={`c${entry.data.id}`}
                                        className={`rounded-lg border p-3 ${
                                            entry.data.is_internal
                                                ? 'border-l-2 border-border border-l-amber-500 bg-surface'
                                                : 'border-border bg-raised'
                                        }`}
                                    >
                                        <div className="flex items-center gap-2">
                                            <Avatar
                                                name={entry.data.author?.name ?? 'Unknown'}
                                            />
                                            <span className="text-xs font-medium text-ink">
                                                {entry.data.author?.name ?? 'Unknown'}
                                            </span>
                                            {entry.data.is_internal && (
                                                <span
                                                    title="Internal note — clients cannot see this"
                                                    className="flex items-center gap-1 text-[11px] text-amber-600 dark:text-amber-500"
                                                >
                                                    <Lock className="size-3" />
                                                    Internal
                                                </span>
                                            )}
                                            <span className="text-[11px] text-ink-subtle">
                                                {relativeTime(entry.data.created_at)}
                                                {entry.data.edited_at && ' · edited'}
                                            </span>
                                            {entry.data.can_edit && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/comments/${entry.data.id}`,
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className="ml-auto rounded p-1 text-ink-subtle hover:text-danger"
                                                    aria-label="Delete comment"
                                                >
                                                    <Trash2 className="size-3" />
                                                </button>
                                            )}
                                        </div>
                                        <div className="mt-2">
                                            <RichTextView value={entry.data.body} />
                                        </div>
                                    </li>
                                ),
                            )}
                        </ol>

                        {auth.user && (
                            <div className="mt-5">
                                <RichTextEditor
                                    value={body}
                                    onChange={setBody}
                                    placeholder="Leave a comment…"
                                    onSubmit={submitComment}
                                />

                                <div className="mt-2 flex items-center gap-3">
                                    <Button
                                        size="sm"
                                        disabled={!body}
                                        onClick={submitComment}
                                    >
                                        Comment
                                    </Button>

                                    {can.comment_internally && (
                                        <button
                                            type="button"
                                            onClick={() => setInternal((i) => !i)}
                                            className={`flex items-center gap-1.5 rounded-lg border px-2 py-1 text-xs transition ${
                                                internal
                                                    ? 'border-amber-500/40 bg-amber-500/10 text-amber-600 dark:text-amber-500'
                                                    : 'border-accent/40 bg-accent-soft text-accent'
                                            }`}
                                        >
                                            {internal ? (
                                                <>
                                                    <EyeOff className="size-3" />
                                                    Internal note
                                                </>
                                            ) : (
                                                <>
                                                    <Eye className="size-3" />
                                                    Visible to client
                                                </>
                                            )}
                                        </button>
                                    )}

                                    <span className="text-[11px] text-ink-subtle">
                                        ⌘↵ to submit
                                    </span>
                                </div>

                                {/* The mistake worth designing against: telling a client
                                    something that was meant for the team. */}
                                {!internal && can.comment_internally && (
                                    <p className="mt-2 text-[11px] text-accent">
                                        This comment will be visible to the client on this
                                        project.
                                    </p>
                                )}
                            </div>
                        )}
                    </section>
                </div>

                <aside className="divide-y divide-border lg:border-l lg:border-border lg:pl-6">
                    <SidebarRow label="Status">
                        {can.update ? (
                            <Popover
                                align="right"
                                label="Change status"
                                trigger={() => (
                                    <span className="flex items-center gap-2 px-1.5 py-1 text-sm text-ink">
                                        <StatusDot status={issue.status} />
                                        {issue.status.name}
                                    </span>
                                )}
                            >
                                {(close) =>
                                    statuses.map((status) => (
                                        <PopoverItem
                                            key={status.id}
                                            selected={status.id === issue.status.id}
                                            onSelect={() => {
                                                close();
                                                patch({ status_id: status.id });
                                            }}
                                        >
                                            <StatusDot status={status} />
                                            {status.name}
                                        </PopoverItem>
                                    ))
                                }
                            </Popover>
                        ) : (
                            <span className="flex items-center gap-2 text-sm text-ink">
                                <StatusDot status={issue.status} />
                                {issue.status.name}
                            </span>
                        )}
                    </SidebarRow>

                    <SidebarRow label="Assignee">
                        {can.update ? (
                            <Popover
                                align="right"
                                label="Change assignee"
                                trigger={() => (
                                    <span className="flex items-center gap-2 px-1.5 py-1 text-sm text-ink">
                                        {issue.assignee ? (
                                            <>
                                                <Avatar name={issue.assignee.name} />
                                                {issue.assignee.name}
                                            </>
                                        ) : (
                                            <span className="text-ink-subtle">Unassigned</span>
                                        )}
                                    </span>
                                )}
                            >
                                {(close) => (
                                    <>
                                        <PopoverItem
                                            selected={!issue.assignee}
                                            onSelect={() => {
                                                close();
                                                patch({ assignee_id: null });
                                            }}
                                        >
                                            Unassigned
                                        </PopoverItem>
                                        {facets.members.map((member) => (
                                            <PopoverItem
                                                key={member.id}
                                                selected={member.id === issue.assignee?.id}
                                                onSelect={() => {
                                                    close();
                                                    patch({ assignee_id: member.id });
                                                }}
                                            >
                                                <Avatar name={member.name} />
                                                {member.name}
                                            </PopoverItem>
                                        ))}
                                    </>
                                )}
                            </Popover>
                        ) : (
                            <span className="text-sm text-ink">
                                {issue.assignee?.name ?? '—'}
                            </span>
                        )}
                    </SidebarRow>

                    <SidebarRow label="Priority">
                        {can.update ? (
                            <Popover
                                align="right"
                                label="Change priority"
                                trigger={() => (
                                    <span className="flex items-center gap-2 px-1.5 py-1 text-sm text-ink">
                                        <PriorityBars
                                            priority={issue.priority}
                                            color={issue.priority_color}
                                            label={issue.priority_label}
                                        />
                                        {issue.priority_label}
                                    </span>
                                )}
                            >
                                {(close) =>
                                    facets.priorities.map((option) => (
                                        <PopoverItem
                                            key={option.value}
                                            selected={option.value === issue.priority}
                                            onSelect={() => {
                                                close();
                                                patch({ priority: option.value });
                                            }}
                                        >
                                            <PriorityBars
                                                priority={option.value}
                                                color={option.color}
                                                label={option.label}
                                            />
                                            {option.label}
                                        </PopoverItem>
                                    ))
                                }
                            </Popover>
                        ) : (
                            <span className="text-sm text-ink">{issue.priority_label}</span>
                        )}
                    </SidebarRow>

                    <SidebarRow label="Type">
                        <span className="flex items-center gap-2 text-sm text-ink capitalize">
                            <TypeIcon type={issue.type} />
                            {issue.type}
                        </span>
                    </SidebarRow>

                    <SidebarRow label="Labels">
                        <div className="flex flex-wrap items-center gap-1">
                            {issue.labels.map((label: LabelChip) => (
                                <LabelPill key={label.id} label={label} />
                            ))}

                            {can.update && (
                                <Popover
                                    align="right"
                                    label="Edit labels"
                                    trigger={() => (
                                        <span className="flex items-center gap-1 rounded border border-dashed border-border-strong px-1.5 py-0.5 text-[11px] text-ink-subtle">
                                            <Tag className="size-3" />
                                            Edit
                                        </span>
                                    )}
                                >
                                    {() =>
                                        facets.labels.length === 0 ? (
                                            <p className="px-2 py-1.5 text-xs text-ink-subtle">
                                                No labels yet.{' '}
                                                <Link
                                                    href="/labels"
                                                    className="text-accent hover:underline"
                                                >
                                                    Create one
                                                </Link>
                                            </p>
                                        ) : (
                                            facets.labels.map((label) => {
                                                const on = issue.labels.some(
                                                    (l: LabelChip) => l.id === label.id,
                                                );

                                                return (
                                                    <PopoverItem
                                                        key={label.id}
                                                        selected={on}
                                                        onSelect={() =>
                                                            patch({
                                                                labels: on
                                                                    ? issue.labels
                                                                          .filter(
                                                                              (l: LabelChip) =>
                                                                                  l.id !== label.id,
                                                                          )
                                                                          .map((l: LabelChip) => l.id)
                                                                    : [
                                                                          ...issue.labels.map(
                                                                              (l: LabelChip) => l.id,
                                                                          ),
                                                                          label.id,
                                                                      ],
                                                            })
                                                        }
                                                    >
                                                        <span
                                                            aria-hidden
                                                            className="size-2 rounded-full"
                                                            style={{ backgroundColor: label.color }}
                                                        />
                                                        <span className="truncate">{label.name}</span>
                                                    </PopoverItem>
                                                );
                                            })
                                        )
                                    }
                                </Popover>
                            )}
                        </div>
                    </SidebarRow>

                    {can.update && (
                        <SidebarRow label="Visibility">
                            <button
                                type="button"
                                onClick={() =>
                                    patch({
                                        visibility:
                                            issue.visibility === 'client' ? 'internal' : 'client',
                                    })
                                }
                                className="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink"
                            >
                                {issue.visibility === 'client' ? (
                                    <>
                                        <Eye className="size-3" /> Client can see this
                                    </>
                                ) : (
                                    <>
                                        <EyeOff className="size-3" /> Internal only
                                    </>
                                )}
                            </button>
                        </SidebarRow>
                    )}

                    {issue.relations.length > 0 && (
                        <SidebarRow label="Linked">
                            <ul className="space-y-1">
                                {issue.relations.map((relation) => (
                                    <li key={relation.id} className="text-xs">
                                        <span className="text-ink-subtle">
                                            {relation.label}{' '}
                                        </span>
                                        <Link
                                            href={`/issues/${relation.issue.key}`}
                                            className="font-mono text-accent hover:underline"
                                        >
                                            {relation.issue.key}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </SidebarRow>
                    )}

                    <SidebarRow label="Reporter">
                        <span className="text-sm text-ink">
                            {issue.reporter?.name ?? 'Unknown'}
                        </span>
                    </SidebarRow>

                    {diagnostics && <Diagnostics data={diagnostics} />}
                </aside>
            </div>
        </AppLayout>
    );
}
