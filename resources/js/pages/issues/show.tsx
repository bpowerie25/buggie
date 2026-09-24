import { Attachments, type AttachmentRow } from '@/components/attachments';
import { formatDuration, parseDuration } from '@/lib/duration';
import {
    CustomFieldInput,
    CustomFieldValueText,
    type CustomFieldWithValue,
} from '@/components/custom-field-input';
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
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { JSONContent } from '@tiptap/react';
import { Copy, Eye, EyeOff, Lock, Plus, Tag, Timer, Trash2, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/** Whoever wrote or did something, as the reader is allowed to see them. */
interface Actor {
    id: number | null;
    name: string;
    role: 'staff' | 'client';
}

interface Comment {
    id: number;
    body: JSONContent;
    is_internal: boolean;
    author: Actor;
    /** Who a public comment reaches now, in words. Staff only. */
    audience: string | null;
    created_at: string;
    edited_at: string | null;
    can_edit: boolean;
}

interface Event {
    id: number;
    type: string;
    data: Record<string, unknown>;
    actor: Actor | null;
    is_internal: boolean;
    created_at: string;
}

type ClientAudience = 'default' | 'project' | 'specific';

interface Issue extends IssueRow {
    description: JSONContent | null;
    reporter: Actor | null;
    /** The issue this one was closed as a duplicate of, if the reader could open it. */
    duplicate_of: { key: string; title: string } | null;
    /** Who sent a widget report and how sure we are. Staff only. */
    widget_reporter: {
        identity: 'anonymous' | 'email_unverified' | 'identified' | 'verified';
        label: string;
        name: string | null;
        email: string | null;
        page_url: string | null;
        linked: boolean;
        candidates: { id: number; name: string; matches: boolean }[];
    } | null;
    visibility: VisibilityValue;
    client_audience: ClientAudience;
    /** Staff only; empty for a client. */
    client_share_ids: number[];
    /** Who among the clients can see it, in words. Staff only; null for a client. */
    audience_label: string | null;
    start_on: string | null;
    due_on: string | null;
    version: { id: number; name: string } | null;
    phase: { id: number; name: string } | null;
    created_at: string;
    watchers: Person[];
    watching: boolean;
    relations: {
        id: number;
        type: string;
        label: string;
        issue: { key: string; title: string; status: string; open: boolean };
    }[];
}

const AUDIENCE_OPTIONS: { value: 'internal' | ClientAudience; label: string; hint: string }[] = [
    { value: 'internal', label: 'Internal only', hint: 'No client sees it.' },
    { value: 'default', label: 'Default', hint: 'Client managers, the reporter and watchers.' },
    { value: 'project', label: 'All clients on this project', hint: 'Every client who holds the project.' },
    { value: 'specific', label: 'Specific clients', hint: 'The default, plus the clients you pick.' },
];

/**
 * Who sees the issue: internal, or which clients. Saves as it changes, like the
 * rest of the sidebar. Only clients on this project are offered, and the server
 * refuses anybody else.
 */
function AudienceControl({
    issue,
    clients,
    onChange,
}: {
    issue: Issue;
    clients: Person[];
    onChange: (payload: RequestPayload) => void;
}) {
    const error = (usePage().props.errors as Record<string, string>)?.client_share_ids;
    const current = issue.visibility === 'internal' ? 'internal' : issue.client_audience;
    const [picking, setPicking] = useState(current === 'specific');
    const shared = issue.client_share_ids;

    function choose(value: 'internal' | ClientAudience) {
        if (value === 'internal') {
            setPicking(false);
            onChange({ visibility: 'internal' });
            return;
        }

        if (value === 'specific') {
            // Nothing is saved until somebody is picked: "specific" with nobody named
            // is refused, and would only be the default by another name.
            setPicking(true);
            return;
        }

        setPicking(false);
        onChange({ visibility: 'client', client_audience: value });
    }

    function toggle(id: number) {
        const next = shared.includes(id) ? shared.filter((x) => x !== id) : [...shared, id];

        if (next.length === 0) return;

        onChange({ visibility: 'client', client_audience: 'specific', client_share_ids: next });
    }

    const shown = picking ? 'specific' : current;

    return (
        <div className="space-y-1.5">
            <Popover
                align="right"
                label="Change who can see this"
                trigger={() => (
                    <span className="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-ink-muted transition hover:text-ink">
                        {shown === 'internal' ? <EyeOff className="size-3" /> : <Eye className="size-3" />}
                        {AUDIENCE_OPTIONS.find((o) => o.value === shown)?.label}
                    </span>
                )}
            >
                {(close) =>
                    AUDIENCE_OPTIONS.map((option) => (
                        <PopoverItem
                            key={option.value}
                            selected={option.value === shown}
                            onSelect={() => {
                                close();
                                choose(option.value);
                            }}
                        >
                            <span className="min-w-0">
                                <span className="block">{option.label}</span>
                                <span className="block text-[11px] text-ink-subtle">{option.hint}</span>
                            </span>
                        </PopoverItem>
                    ))
                }
            </Popover>

            {picking && (
                <div className="rounded-lg border border-border bg-surface p-2">
                    {clients.length === 0 ? (
                        <p className="text-xs text-ink-subtle">No clients hold this project.</p>
                    ) : (
                        clients.map((client) => (
                            <label key={client.id} className="flex items-center gap-2 py-0.5 text-xs text-ink-muted">
                                <input
                                    type="checkbox"
                                    checked={shared.includes(client.id)}
                                    // The last one cannot be unticked here: choose another
                                    // audience instead.
                                    disabled={shared.length === 1 && shared.includes(client.id)}
                                    onChange={() => toggle(client.id)}
                                />
                                {client.name}
                            </label>
                        ))
                    )}
                </div>
            )}

            {error && <p className="text-xs text-danger">{error}</p>}
        </div>
    );
}

/**
 * A comment's left border, so the thread can be read at a glance: an internal note,
 * the team writing to the client, or the client writing.
 */
function commentBorder(comment: Comment): string {
    if (comment.is_internal) return '#F59E0B';

    return comment.author.role === 'client' ? '#10B981' : '#3B82F6';
}

const IDENTITY_STYLES: Record<string, string> = {
    verified: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    identified: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    email_unverified: 'bg-amber-500/10 text-amber-600 dark:text-amber-500',
    anonymous: 'bg-surface text-ink-muted',
};

/**
 * Who sent a widget report, how sure we are, and — if they are not linked to a
 * client yet — the way to link them. Linking is what lets them see the issue.
 */
function WidgetReporter({
    reporter,
    linkedTo,
    issueKey,
    canLink,
}: {
    reporter: NonNullable<Issue['widget_reporter']>;
    linkedTo: string | null;
    issueKey: string;
    canLink: boolean;
}) {
    const error = (usePage().props.errors as Record<string, string>)?.user_id;

    return (
        <div className="min-w-0 space-y-1 text-sm">
            <p className="flex flex-wrap items-center gap-1.5 text-ink">
                <span className="truncate">{reporter.name ?? reporter.email ?? 'Anonymous'}</span>
                <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${IDENTITY_STYLES[reporter.identity]}`}>
                    {reporter.label}
                </span>
            </p>
            {reporter.name && reporter.email && (
                <p className="truncate text-xs text-ink-subtle">{reporter.email}</p>
            )}
            <p className="truncate text-xs text-ink-subtle">
                via widget
                {reporter.page_url && (
                    <>
                        {' · '}
                        <a href={reporter.page_url} target="_blank" rel="noreferrer noopener" className="hover:text-accent">
                            {reporter.page_url}
                        </a>
                    </>
                )}
            </p>

            {linkedTo ? (
                <p className="text-xs text-ink-muted">Linked to {linkedTo}, who can see this issue.</p>
            ) : (
                canLink &&
                reporter.candidates.length > 0 && (
                    <Popover
                        align="right"
                        label="Link to client"
                        trigger={() => (
                            <span className="rounded border border-dashed border-border-strong px-1.5 py-0.5 text-[11px] text-ink-subtle">
                                Link to client
                            </span>
                        )}
                    >
                        {(close) =>
                            reporter.candidates.map((client) => (
                                <PopoverItem
                                    key={client.id}
                                    onSelect={() => {
                                        close();
                                        router.post(`/issues/${issueKey}/reporter`, { user_id: client.id }, { preserveScroll: true });
                                    }}
                                >
                                    {client.name}
                                    {client.matches && (
                                        <span className="ml-auto text-[10px] text-ink-subtle">same email</span>
                                    )}
                                </PopoverItem>
                            ))
                        }
                    </Popover>
                )
            )}

            {error && <p className="text-xs text-danger">{error}</p>}
        </div>
    );
}

/**
 * Close this issue as a duplicate of another: type its key, and the issue closes,
 * says so in its thread, and its reporter and watchers follow the original instead.
 */
function MarkDuplicate({ issueKey }: { issueKey: string }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ key: '' });

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mt-1.5 flex items-center gap-1 rounded border border-dashed border-border-strong px-1.5 py-0.5 text-[11px] text-ink-subtle hover:text-ink"
            >
                <Copy className="size-3" />
                Mark as duplicate…
            </button>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                post(`/issues/${issueKey}/duplicate`, { preserveScroll: true, onSuccess: () => setOpen(false) });
            }}
            className="mt-1.5 space-y-1.5"
        >
            <p className="text-[11px] text-ink-subtle">
                Duplicate of which issue? This one closes, and its reporter and watchers follow that one.
            </p>
            <div className="flex gap-1.5">
                <input
                    value={data.key}
                    autoFocus
                    placeholder="KD-12"
                    aria-label="Key of the original issue"
                    onChange={(e) => setData('key', e.target.value)}
                    onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                    className="h-7 w-24 rounded-md border border-border-strong bg-raised px-2 font-mono text-xs text-ink uppercase focus:border-accent focus:outline-none"
                />
                <Button type="submit" size="sm" disabled={processing || data.key.trim() === ''}>
                    Close as duplicate
                </Button>
            </div>
            {errors.key && <p className="text-[11px] text-danger">{errors.key}</p>}
        </form>
    );
}

/** Renders one activity event as a sentence. */
function typeLabel(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

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
            // Stored as the enum value ('bug'), shown as its label ('Bug').
            return d.from
                ? `${actor} changed the type from ${typeLabel(d.from as string)} to ${typeLabel(d.to as string)}`
                : `${actor} changed the type to ${typeLabel(d.to as string)}`;
        case 'title_changed':
            return `${actor} renamed this issue`;
        case 'label_added':
            return `${actor} added the label ${d.name as string}`;
        case 'label_removed':
            return `${actor} removed the label ${d.name as string}`;
        case 'awaiting_client':
            return `${actor} replied and is waiting on the client — moved to ${(d.to as { name: string })?.name}`;
        case 'client_replied':
            return `${event.actor ? actor : ((d.name as string) ?? 'The client')} replied — moved back to ${(d.to as { name: string })?.name}`;
        case 'client_reminded':
            return 'The client was reminded that a reply is waiting';
        case 'auto_closed':
            return `Closed after ${d.days as number} days without a reply`;
        case 'dates_changed': {
            const to = d.to as { start_on: string | null; due_on: string | null };
            const span = [to.start_on, to.due_on].filter(Boolean).join(' – ');
            return span ? `${actor} rescheduled this to ${span}` : `${actor} cleared the dates`;
        }
        case 'marked_duplicate':
            return d.of ? `${actor} closed this as a duplicate of ${d.of as string}` : `${actor} closed this as a duplicate`;
        case 'audience_changed': {
            const clients = (d.clients as string[] | undefined) ?? [];
            const to =
                d.to === 'project'
                    ? 'all clients on this project'
                    : d.to === 'specific'
                      ? `the default audience plus ${clients.join(', ')}`
                      : 'the default audience';
            return `${actor} shared this with ${to}`;
        }
        case 'visibility_changed':
            return d.to === 'client'
                ? `${actor} made this visible to the client`
                : `${actor} made this internal only`;
        case 'phase_changed':
            return d.to
                ? `${actor} moved this to the ${d.to} phase`
                : `${actor} took this out of the ${d.from} phase`;
        case 'version_changed':
            return d.to
                ? `${actor} put this in ${d.to}`
                : `${actor} took this out of ${d.from}`;
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

/**
 * Logging time, and what has been logged.
 *
 * Staff only — this whole component is absent from a client's payload rather than
 * hidden by it. The duration box shows what it understood before anything is saved,
 * because "90" meaning ninety minutes is a guess somebody will get wrong otherwise.
 */
interface TimeEntryRow {
    id: number;
    duration: string;
    spent_on: string;
    note: string | null;
    billable: boolean;
    user: string;
    can_delete: boolean;
}

interface TimeSummary {
    entries: TimeEntryRow[];
    total: string;
    total_minutes: number;
    estimate: string;
    estimate_minutes: number | null;
    over_by: string | null;
    can_log: boolean;
}

function TimePanel({
    issueKey,
    time,
    canEstimate,
}: {
    issueKey: string;
    time: TimeSummary;
    canEstimate: boolean;
}) {
    const [duration, setDuration] = useState('');
    const [note, setNote] = useState('');
    const [spentOn, setSpentOn] = useState(() => new Date().toISOString().slice(0, 10));
    const [billable, setBillable] = useState(true);
    const [estimate, setEstimate] = useState(time.estimate_minutes === null ? '' : time.estimate);

    return (
        <section className="mt-8">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                    Time
                </h2>
                <span className="text-xs text-ink-subtle">
                    {time.total} logged
                    {time.estimate_minutes !== null && ` · ${time.estimate} estimated`}
                    {time.over_by && ` · ${time.over_by} over`}
                </span>
            </div>

            {time.entries.length > 0 && (
                <ul className="mt-3 divide-y divide-border rounded-xl border border-border">
                    {time.entries.map((entry) => (
                        <li key={entry.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                            <span className="w-24 shrink-0 text-xs text-ink-subtle">
                                {entry.spent_on}
                            </span>
                            <span className="w-28 shrink-0 truncate text-ink-muted">
                                {entry.user}
                            </span>
                            <span className="min-w-0 flex-1 truncate text-ink-muted">
                                {entry.note}
                            </span>
                            <span className="shrink-0 text-ink">
                                {entry.duration}
                                {!entry.billable && (
                                    <span className="ml-1.5 text-xs text-ink-subtle">unbilled</span>
                                )}
                            </span>
                            {entry.can_delete && (
                                <button
                                    type="button"
                                    aria-label="Remove entry"
                                    className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-danger"
                                    onClick={() =>
                                        router.delete(`/time/${entry.id}`, { preserveScroll: true })
                                    }
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {time.can_log && (
                <form
                    className="mt-3 flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `/issues/${issueKey}/time`,
                            { duration, spent_on: spentOn, note, billable },
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setDuration('');
                                    setNote('');
                                },
                            },
                        );
                    }}
                >
                    <div>
                        <input
                            value={duration}
                            onChange={(e) => setDuration(e.target.value)}
                            placeholder="1h 30m"
                            aria-label="How long"
                            className="w-24 rounded-lg border border-border bg-surface px-2 py-1.5 text-sm text-ink"
                        />
                        {/* What it understood, before it is saved. */}
                        <span className="mt-0.5 block h-4 text-xs text-ink-subtle">
                            {duration === ''
                                ? ''
                                : parseDuration(duration) === null
                                  ? 'not a length of time'
                                  : `= ${formatDuration(parseDuration(duration))}`}
                        </span>
                    </div>

                    <input
                        type="date"
                        value={spentOn}
                        max={new Date().toISOString().slice(0, 10)}
                        aria-label="Day the work happened"
                        onChange={(e) => setSpentOn(e.target.value)}
                        className="mb-4 rounded-lg border border-border bg-surface px-2 py-1.5 text-sm text-ink"
                    />

                    <input
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="What you did (optional)"
                        aria-label="Note"
                        className="mb-4 min-w-48 flex-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-sm text-ink"
                    />

                    <label className="mb-4 flex items-center gap-1.5 text-xs text-ink-muted">
                        <input
                            type="checkbox"
                            checked={billable}
                            onChange={(e) => setBillable(e.target.checked)}
                        />
                        Billable
                    </label>

                    <Button type="submit" size="sm" className="mb-4" disabled={!duration}>
                        Log
                    </Button>

                    {/* The optional half. Typing a duration stays the primary way in,
                        because plenty of people never want a clock — this sits beside
                        it rather than replacing it. */}
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        className="mb-4"
                        onClick={() =>
                            router.post(
                                `/issues/${issueKey}/timer`,
                                { billable },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Timer className="size-3.5" />
                        Start timer
                    </Button>
                </form>
            )}

            {canEstimate && (
                <form
                    className="mt-1 flex items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.patch(
                            `/issues/${issueKey}/estimate`,
                            { estimate },
                            { preserveScroll: true },
                        );
                    }}
                >
                    <span className="text-xs text-ink-subtle">Estimate</span>
                    <input
                        value={estimate}
                        onChange={(e) => setEstimate(e.target.value)}
                        placeholder="none"
                        aria-label="Estimate"
                        className="w-24 rounded-lg border border-border bg-surface px-2 py-1 text-sm text-ink"
                    />
                    <Button type="submit" size="sm" variant="secondary">
                        Save
                    </Button>
                </form>
            )}
        </section>
    );
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
    relationTypes = [],
    projectClients = [],
    composer = null,
    versions = [],
    phases = [],
    customFields = [],
    time = null,
    parent = null,
    children = [],
}: {
    issue: Issue;
    comments: Comment[];
    events: Event[];
    attachments: AttachmentRow[];
    statuses: IssueStatus[];
    facets: Facets;
    /** Null for clients, and for issues with no captured context. */
    diagnostics: DiagnosticsData | null;
    relationTypes?: { value: string; label: string }[];
    /** Clients on this project who can be named in a specific audience. Staff only. */
    projectClients?: Person[];
    /** Who a message from the composer reaches, and whether it can await the client. Staff only. */
    composer?: {
        internal: string;
        public: string | null;
        can_await: boolean;
        awaiting_status: string | null;
    } | null;
    versions?: { id: number; name: string; released: boolean }[];
    phases?: { id: number; name: string }[];
    customFields?: CustomFieldWithValue[];
    parent?: { key: string; title: string } | null;
    children?: { key: string; title: string; status: string | null; open: boolean }[];
    time?: TimeSummary | null;
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

    function replyAndAwait() {
        if (!body) return;

        router.post(
            `/issues/${issue.key}/await-client`,
            { body },
            {
                preserveScroll: true,
                only: ['issue', 'comments', 'events', 'composer', 'flash', 'errors'],
                onSuccess: () => setBody(null),
            },
        );
    }

    // Staff see who can see what; a client's payload never has the details to show.
    const staffView = composer !== null;

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
                                {/* A client is told only that it is theirs: the names
                                    of other clients are not theirs to know. */}
                                {issue.audience_label ?? 'Shared with you'}
                            </span>
                        )}
                    </div>

                    {issue.duplicate_of && (
                        <p className="mt-2 flex items-center gap-1.5 text-sm text-ink-muted">
                            <Copy className="size-3.5" />
                            Duplicate of{' '}
                            <Link href={`/issues/${issue.duplicate_of.key}`} className="font-medium text-accent hover:underline">
                                {issue.duplicate_of.key}
                            </Link>
                            <span className="truncate text-ink-subtle">{issue.duplicate_of.title}</span>
                        </p>
                    )}

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

                    {time && <TimePanel issueKey={issue.key} time={time} canEstimate={can.update} />}

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
                                        {/* Staff can see at a glance what the client can
                                            see. A client only ever receives public ones. */}
                                        {staffView ? (
                                            entry.data.is_internal ? (
                                                <Lock aria-label="Internal" className="ml-1 size-3 shrink-0 text-amber-500" />
                                            ) : (
                                                <Eye aria-label="Visible to client" className="ml-1 size-3 shrink-0 text-sky-500" />
                                            )
                                        ) : (
                                            <span className="ml-2 size-1.5 rounded-full bg-border-strong" />
                                        )}
                                        <span>
                                            {eventSentence(entry.data)}
                                            {staffView && entry.data.actor && (
                                                <span className="text-ink-subtle">
                                                    {' '}({entry.data.actor.role === 'client' ? 'Client' : 'Staff'})
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-ink-subtle">
                                            · {relativeTime(entry.at)}
                                        </span>
                                    </li>
                                ) : (
                                    <li
                                        key={`c${entry.data.id}`}
                                        style={{ borderLeftColor: commentBorder(entry.data) }}
                                        className={`rounded-lg border border-l-4 border-border p-3 ${
                                            entry.data.is_internal ? 'bg-surface' : 'bg-raised'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Avatar name={entry.data.author.name} />
                                            <span className="text-xs font-medium text-ink">
                                                {entry.data.author.name}
                                            </span>
                                            <span
                                                className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                                    entry.data.author.role === 'client'
                                                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                        : 'bg-surface text-ink-muted'
                                                }`}
                                            >
                                                {entry.data.author.role === 'client' ? 'Client' : 'Staff'}
                                            </span>
                                            {entry.data.is_internal ? (
                                                <span
                                                    title="Internal note — clients cannot see this"
                                                    className="flex items-center gap-1 rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-500"
                                                >
                                                    <Lock className="size-3" />
                                                    Internal
                                                </span>
                                            ) : (
                                                staffView && (
                                                    <span
                                                        title={entry.data.audience ?? undefined}
                                                        className="flex cursor-help items-center gap-1 rounded bg-sky-500/10 px-1.5 py-0.5 text-[10px] font-medium text-sky-600 dark:text-sky-400"
                                                    >
                                                        <Eye className="size-3" />
                                                        Visible to client
                                                    </span>
                                                )
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

                                {/* Who this reaches, said in words and always shown:
                                    telling a client something meant for the team is
                                    the mistake worth designing against. */}
                                {composer && (
                                    <p
                                        className={`mt-2 flex items-center gap-1.5 text-[11px] ${
                                            internal ? 'text-amber-600 dark:text-amber-500' : 'text-sky-600 dark:text-sky-400'
                                        }`}
                                    >
                                        {internal ? <Lock className="size-3" /> : <Eye className="size-3" />}
                                        {internal
                                            ? composer.internal
                                            : issue.visibility === 'internal'
                                              ? 'This issue is internal — no client can see it'
                                              : composer.public}
                                    </p>
                                )}

                                <div className="mt-2 flex flex-wrap items-center gap-3">
                                    {can.comment_internally && (
                                        <div
                                            role="radiogroup"
                                            aria-label="Who this is for"
                                            className="flex overflow-hidden rounded-lg border border-border text-xs"
                                        >
                                            <button
                                                type="button"
                                                role="radio"
                                                aria-checked={!internal}
                                                onClick={() => setInternal(false)}
                                                className={`flex items-center gap-1.5 px-2.5 py-1 transition ${
                                                    !internal
                                                        ? 'bg-sky-500/15 font-medium text-sky-700 dark:text-sky-300'
                                                        : 'text-ink-muted hover:text-ink'
                                                }`}
                                            >
                                                <Eye className="size-3" />
                                                Comment
                                            </button>
                                            <button
                                                type="button"
                                                role="radio"
                                                aria-checked={internal}
                                                onClick={() => setInternal(true)}
                                                className={`flex items-center gap-1.5 border-l border-border px-2.5 py-1 transition ${
                                                    internal
                                                        ? 'bg-amber-500/15 font-medium text-amber-700 dark:text-amber-300'
                                                        : 'text-ink-muted hover:text-ink'
                                                }`}
                                            >
                                                <Lock className="size-3" />
                                                Internal note
                                            </button>
                                        </div>
                                    )}

                                    <Button size="sm" disabled={!body} onClick={submitComment}>
                                        {internal ? 'Add internal note' : 'Comment'}
                                    </Button>

                                    {/* Only a public reply on a client-visible issue can wait
                                        on the client, and only where the project has an
                                        awaiting-client status. */}
                                    {!internal && composer?.can_await && can.update && (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={!body}
                                            onClick={replyAndAwait}
                                            title={`Posts this reply and moves the issue to ${composer.awaiting_status}`}
                                        >
                                            Reply & await client
                                        </Button>
                                    )}

                                    <span className="text-[11px] text-ink-subtle">⌘↵ to submit</span>
                                </div>
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
                                        {facets.assignees.map((member) => (
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
                        {can.update ? (
                            <Popover
                                align="right"
                                label="Change type"
                                trigger={() => (
                                    <span className="flex items-center gap-2 px-1.5 py-1 text-sm text-ink capitalize">
                                        <TypeIcon type={issue.type} />
                                        {issue.type}
                                    </span>
                                )}
                            >
                                {(close) =>
                                    facets.types.map((option) => (
                                        <PopoverItem
                                            key={option.value}
                                            selected={option.value === issue.type}
                                            onSelect={() => {
                                                close();
                                                patch({ type: option.value });
                                            }}
                                        >
                                            <TypeIcon type={option.value} />
                                            {option.label}
                                        </PopoverItem>
                                    ))
                                }
                            </Popover>
                        ) : (
                            <span className="flex items-center gap-2 text-sm text-ink capitalize">
                                <TypeIcon type={issue.type} />
                                {issue.type}
                            </span>
                        )}
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
                            <AudienceControl
                                issue={issue}
                                clients={projectClients}
                                onChange={patch}
                            />
                        </SidebarRow>
                    )}

                    {/* Only once the project has phases; until then the row is noise. */}
                    {(phases.length > 0 || issue.phase) && (
                        <SidebarRow label="Phase">
                            {can.update ? (
                                <select
                                    value={issue.phase?.id ?? ''}
                                    aria-label="Phase"
                                    onChange={(e) =>
                                        router.patch(
                                            `/issues/${issue.key}`,
                                            { phase_id: e.target.value ? Number(e.target.value) : null },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="w-full rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm text-ink transition hover:border-border"
                                >
                                    <option value="">No phase</option>
                                    {phases.map((phase) => (
                                        <option key={phase.id} value={phase.id}>
                                            {phase.name}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <span className="text-sm text-ink">{issue.phase?.name}</span>
                            )}
                        </SidebarRow>
                    )}

                    <SidebarRow label="Release">
                        {can.update ? (
                            <select
                                value={issue.version?.id ?? ''}
                                aria-label="Release"
                                onChange={(e) =>
                                    router.patch(
                                        `/issues/${issue.key}`,
                                        { version_id: e.target.value ? Number(e.target.value) : null },
                                        { preserveScroll: true },
                                    )
                                }
                                className="w-full rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm text-ink transition hover:border-border"
                            >
                                <option value="">Not in a release</option>
                                {versions.map((version) => (
                                    <option key={version.id} value={version.id}>
                                        {version.name}
                                        {version.released ? ' (released)' : ''}
                                    </option>
                                ))}
                            </select>
                        ) : issue.version ? (
                            <Link
                                href={`/projects/${issue.project.slug}/versions/${issue.version.id}`}
                                className="text-sm text-accent hover:underline"
                            >
                                {issue.version.name}
                            </Link>
                        ) : (
                            <span className="text-sm text-ink-subtle">Not in a release</span>
                        )}
                    </SidebarRow>

                    {/* Beside Due, because the two are one decision: a timeline
                        bar needs both ends, and setting one without the other is
                        how an issue ends up as a marker rather than a span. */}
                    <SidebarRow label="Starts">
                        {can.update ? (
                            <input
                                type="date"
                                value={issue.start_on ?? ''}
                                aria-label="Start date"
                                onChange={(e) =>
                                    router.patch(
                                        `/issues/${issue.key}`,
                                        // Empty clears it, as with the due date.
                                        { start_on: e.target.value || null },
                                        { preserveScroll: true },
                                    )
                                }
                                className="w-full rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm text-ink transition hover:border-border"
                            />
                        ) : (
                            <span className="text-sm text-ink">{issue.start_on ?? 'No date'}</span>
                        )}
                    </SidebarRow>

                    <SidebarRow label="Due">
                        {can.update ? (
                            <input
                                type="date"
                                value={issue.due_on ?? ''}
                                aria-label="Due date"
                                onChange={(e) =>
                                    router.patch(
                                        `/issues/${issue.key}`,
                                        // Empty clears it. A due date you cannot remove
                                        // is a date that stays wrong forever.
                                        { due_on: e.target.value || null },
                                        { preserveScroll: true },
                                    )
                                }
                                className="w-full rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm text-ink transition hover:border-border"
                            />
                        ) : (
                            <span className="text-sm text-ink">{issue.due_on ?? 'No date'}</span>
                        )}
                    </SidebarRow>

                    {customFields.map((field) => (
                        <SidebarRow key={field.id} label={field.name}>
                            {can.update ? (
                                <CustomFieldInput
                                    field={field}
                                    value={field.value}
                                    compact
                                    onChange={(value) =>
                                        patch({
                                            // Only this field is sent. The whole map
                                            // would mean every other field's value
                                            // making a round trip it did not need to,
                                            // and a stale one overwriting an edit made
                                            // in another tab.
                                            custom_fields: { [field.key]: value },
                                        })
                                    }
                                />
                            ) : (
                                <CustomFieldValueText field={field} />
                            )}
                        </SidebarRow>
                    ))}

                    {parent && (
                        <SidebarRow label="Part of">
                            <Link
                                href={`/issues/${parent.key}`}
                                className="text-sm text-accent underline underline-offset-2"
                            >
                                <span className="font-mono text-xs">{parent.key}</span>{' '}
                                {parent.title}
                            </Link>
                        </SidebarRow>
                    )}

                    {children.length > 0 && (
                        <SidebarRow label="Subtasks">
                            <div className="space-y-1">
                                {/* Counted, because "3 of 5 done" is the only thing
                                    anybody wants from a subtask list at a glance. */}
                                <span className="text-xs text-ink-subtle">
                                    {children.filter((c) => !c.open).length} of {children.length} done
                                </span>
                                {children.map((child) => (
                                    <Link
                                        key={child.key}
                                        href={`/issues/${child.key}`}
                                        className="block truncate text-sm text-ink hover:text-accent"
                                    >
                                        <span
                                            className={`font-mono text-xs ${
                                                child.open ? 'text-ink-subtle' : 'text-success'
                                            }`}
                                        >
                                            {child.key}
                                        </span>{' '}
                                        {child.title}
                                    </Link>
                                ))}
                            </div>
                        </SidebarRow>
                    )}

                    {time && (
                        <SidebarRow label="Time">
                            <div className="space-y-1">
                                <div className="flex items-baseline gap-2">
                                    <span className="text-sm text-ink">{time.total}</span>
                                    {time.estimate_minutes !== null && (
                                        <span className="text-xs text-ink-subtle">
                                            of {time.estimate}
                                        </span>
                                    )}
                                </div>
                                {/* Only when it is over. "You are under your estimate"
                                    is not news. */}
                                {time.over_by && (
                                    <span className="text-xs text-danger">
                                        {time.over_by} over
                                    </span>
                                )}
                            </div>
                        </SidebarRow>
                    )}

                    <SidebarRow label="Watching">
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={() =>
                                    router[issue.watching ? 'delete' : 'post'](
                                        `/issues/${issue.key}/watch`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="flex items-center gap-1.5 rounded-md px-1.5 py-1 text-sm text-ink transition hover:bg-surface"
                            >
                                {issue.watching ? (
                                    <>
                                        <Eye className="size-3.5 text-accent" /> Watching
                                    </>
                                ) : (
                                    <>
                                        <EyeOff className="size-3.5 text-ink-subtle" /> Not watching
                                    </>
                                )}
                            </button>

                            {issue.watchers.length > 0 && (
                                <span
                                    className="text-xs text-ink-subtle"
                                    title={issue.watchers.map((w) => w.name).join(', ')}
                                >
                                    {issue.watchers.length}
                                </span>
                            )}
                        </div>
                    </SidebarRow>

                    <SidebarRow label="Linked">
                        <Relations
                            issue={issue}
                            types={relationTypes}
                            editable={can.update}
                        />
                        {can.update && !issue.duplicate_of && <MarkDuplicate issueKey={issue.key} />}
                    </SidebarRow>

                    <SidebarRow label="Reporter">
                        {issue.widget_reporter ? (
                            <WidgetReporter
                                reporter={issue.widget_reporter}
                                linkedTo={issue.widget_reporter.linked ? issue.reporter?.name ?? null : null}
                                issueKey={issue.key}
                                canLink={can.update}
                            />
                        ) : (
                            <span className="text-sm text-ink">
                                {issue.reporter?.name ?? 'Unknown'}
                            </span>
                        )}
                    </SidebarRow>

                    {diagnostics && <Diagnostics data={diagnostics} heading="Diagnostics" />}
                </aside>
            </div>
        </AppLayout>
    );
}

/**
 * Linked issues.
 *
 * The endpoints have existed since M3 and nothing called them, so relations could be
 * read and never made. Keys are typed rather than picked from a list: an agency with
 * a few thousand issues does not want a dropdown, and people know the key of the bug
 * they are thinking of.
 */
function Relations({
    issue,
    types,
    editable,
}: {
    issue: Issue;
    types: { value: string; label: string }[];
    editable: boolean;
}) {
    const [adding, setAdding] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        type: types[0]?.value ?? 'relates_to',
        key: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        post(`/issues/${issue.key}/relations`, {
            preserveScroll: true,
            onSuccess: () => {
                reset('key');
                setAdding(false);
            },
        });
    }

    return (
        <div className="space-y-1.5">
            {issue.relations.length > 0 && (
                <ul className="space-y-1">
                    {issue.relations.map((relation) => (
                        <li key={relation.id} className="group flex items-center gap-1.5 text-xs">
                            <span className="text-ink-subtle">{relation.label}</span>
                            <Link
                                href={`/issues/${relation.issue.key}`}
                                className="font-mono text-accent hover:underline"
                            >
                                {relation.issue.key}
                            </Link>
                            {editable && (
                                <button
                                    type="button"
                                    aria-label={`Unlink ${relation.issue.key}`}
                                    onClick={() =>
                                        router.delete(`/issues/${issue.key}/relations`, {
                                            data: { key: relation.issue.key, type: relation.type },
                                            preserveScroll: true,
                                        })
                                    }
                                    className="ml-auto opacity-0 transition group-hover:opacity-100"
                                >
                                    <X className="size-3 text-ink-subtle hover:text-danger" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {editable &&
                (adding ? (
                    <form onSubmit={submit} className="space-y-1.5">
                        <select
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            className="w-full rounded-md border border-border bg-raised px-1.5 py-1 text-xs text-ink"
                        >
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>

                        <input
                            value={data.key}
                            autoFocus
                            placeholder="WEB-42"
                            aria-label="Issue key to link"
                            onChange={(e) => setData('key', e.target.value.toUpperCase())}
                            onKeyDown={(e) => e.key === 'Escape' && setAdding(false)}
                            className="w-full rounded-md border border-border bg-raised px-1.5 py-1 font-mono text-xs text-ink"
                        />

                        {errors.key && <p className="text-xs text-danger">{errors.key}</p>}

                        <div className="flex gap-1.5">
                            <button
                                type="submit"
                                disabled={processing || data.key === ''}
                                className="rounded-md bg-accent px-2 py-1 text-xs text-accent-ink disabled:opacity-50"
                            >
                                Link
                            </button>
                            <button
                                type="button"
                                onClick={() => setAdding(false)}
                                className="rounded-md px-2 py-1 text-xs text-ink-muted"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                ) : (
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="flex items-center gap-1 text-xs text-ink-subtle transition hover:text-ink"
                    >
                        <Plus className="size-3" />
                        Link an issue
                    </button>
                ))}

            {!editable && issue.relations.length === 0 && (
                <span className="text-xs text-ink-subtle">Nothing linked</span>
            )}
        </div>
    );
}
