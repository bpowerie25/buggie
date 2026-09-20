import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { StatusDot } from '@/components/issue-bits';
import type { IssueStatus, StatusCategory } from '@/types';
import {
    DndContext,
    PointerSensor,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import {
    SortableContext,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import { Check, GripVertical, Plus, Trash2, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Category {
    value: StatusCategory;
    label: string;
    open: boolean;
}

interface Row extends IssueStatus {
    is_default: boolean;
    issues_count: number;
}

const PALETTE = ['#94a3b8', '#64748b', '#f59e0b', '#8b5cf6', '#10b981', '#ef4444', '#0ea5e9'];

function SortableRow({
    status,
    statuses,
    onEdit,
    editing,
    onCancel,
}: {
    status: Row;
    statuses: Row[];
    onEdit: (status: Row) => void;
    editing: boolean;
    onCancel: () => void;
}) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
        useSortable({ id: status.id });

    const [name, setName] = useState(status.name);
    const [color, setColor] = useState(status.color);
    const [wip, setWip] = useState(status.wip_limit === null ? '' : String(status.wip_limit ?? ''));
    const [confirming, setConfirming] = useState(false);
    const [moveTo, setMoveTo] = useState<number | ''>('');

    const others = statuses.filter((s) => s.id !== status.id);
    const openOthers = statuses.filter((s) => s.open && s.id !== status.id);

    function save(extra: Record<string, unknown> = {}) {
        router.patch(
            `/statuses/${status.id}`,
            // Blank clears the limit rather than meaning zero. "No limit" and "a
            // limit of nothing" are different claims and only one is useful.
            { name, color, wip_limit: wip.trim() === '' ? null : Number(wip), ...extra },
            { preserveScroll: true, onSuccess: onCancel },
        );
    }

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`flex items-center gap-2 px-3 py-2 ${isDragging ? 'opacity-50' : ''}`}
        >
            <button
                type="button"
                aria-label={`Reorder ${status.name}`}
                className="cursor-grab text-ink-subtle active:cursor-grabbing"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-3.5" />
            </button>

            {editing ? (
                <>
                    <fieldset className="flex shrink-0 items-center gap-1">
                        <legend className="sr-only">Colour</legend>
                        {PALETTE.map((c) => (
                            <button
                                key={c}
                                type="button"
                                aria-label={`Colour ${c}`}
                                aria-pressed={color === c}
                                onClick={() => setColor(c)}
                                className={`size-4 rounded-full transition ${
                                    color === c ? 'ring-2 ring-accent ring-offset-2 ring-offset-raised' : ''
                                }`}
                                style={{ backgroundColor: c }}
                            />
                        ))}
                    </fieldset>

                    <Input
                        value={name}
                        maxLength={40}
                        autoFocus
                        aria-label="Status name"
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') save();
                            if (e.key === 'Escape') onCancel();
                        }}
                        className="h-8 min-w-0 flex-1"
                    />

                    <input
                        type="number"
                        min={1}
                        max={999}
                        value={wip}
                        placeholder="WIP"
                        aria-label="Work in progress limit"
                        title="How many issues should sit in this column at once. Blank for no limit."
                        onChange={(e) => setWip(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') save();
                            if (e.key === 'Escape') onCancel();
                        }}
                        className="h-8 w-16 shrink-0 rounded-lg border border-border bg-surface px-2 text-sm text-ink"
                    />

                    <Button size="sm" onClick={() => save()}>
                        <Check className="size-3.5" />
                    </Button>
                    <Button size="sm" variant="secondary" onClick={onCancel}>
                        <X className="size-3.5" />
                    </Button>
                </>
            ) : (
                <>
                    <StatusDot status={status} />

                    <button
                        type="button"
                        onClick={() => onEdit(status)}
                        className="min-w-0 flex-1 truncate text-left text-sm text-ink hover:text-accent"
                    >
                        {status.name}
                    </button>

                    {status.wip_limit != null && (
                        <span
                            title="Work in progress limit, shown on the board"
                            className="shrink-0 rounded bg-raised px-1.5 py-0.5 text-[10px] text-ink-subtle"
                        >
                            max {status.wip_limit}
                        </span>
                    )}

                    {status.is_default && (
                        <span className="shrink-0 rounded bg-accent-soft px-1.5 py-0.5 text-[10px] font-medium text-accent">
                            new issues start here
                        </span>
                    )}

                    <span className="w-20 shrink-0 text-right text-[11px] text-ink-subtle">
                        {status.category}
                    </span>

                    <span className="w-16 shrink-0 text-right text-[11px] text-ink-subtle">
                        {status.issues_count} issue{status.issues_count === 1 ? '' : 's'}
                    </span>

                    {!status.is_default && status.open && (
                        <button
                            type="button"
                            onClick={() => save({ is_default: true })}
                            title="New issues start here"
                            className="shrink-0 rounded px-1.5 py-0.5 text-[11px] text-ink-subtle hover:text-accent"
                        >
                            set default
                        </button>
                    )}

                    {confirming ? (
                        <span className="flex shrink-0 items-center gap-1.5">
                            {status.issues_count > 0 && (
                                <select
                                    value={moveTo}
                                    aria-label="Move issues to"
                                    onChange={(e) => setMoveTo(Number(e.target.value))}
                                    className="h-7 rounded-lg border border-border-strong bg-raised px-1.5 text-xs text-ink"
                                >
                                    <option value="">Move issues to…</option>
                                    {others.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <Button
                                size="sm"
                                variant="danger"
                                disabled={status.issues_count > 0 && moveTo === ''}
                                onClick={() =>
                                    router.delete(`/statuses/${status.id}`, {
                                        data: moveTo === '' ? {} : { move_to: moveTo },
                                        preserveScroll: true,
                                        onSuccess: () => setConfirming(false),
                                    })
                                }
                            >
                                Delete
                            </Button>
                            <Button size="sm" variant="secondary" onClick={() => setConfirming(false)}>
                                Cancel
                            </Button>
                        </span>
                    ) : (
                        <button
                            type="button"
                            aria-label={`Delete ${status.name}`}
                            disabled={statuses.length <= 1 || (status.open && openOthers.length === 0)}
                            onClick={() => setConfirming(true)}
                            className="shrink-0 rounded p-1 text-ink-subtle transition hover:text-danger disabled:cursor-not-allowed disabled:opacity-30"
                        >
                            <Trash2 className="size-3.5" />
                        </button>
                    )}
                </>
            )}
        </li>
    );
}

/**
 * Per-project workflow editing.
 *
 * Names and colours are the customer's; the category behind each one is not, and is
 * fixed at creation — changing it later would rewrite what "closed" meant for every
 * issue that passed through.
 */
export function WorkflowEditor({
    projectSlug,
    statuses,
    categories,
}: {
    projectSlug: string;
    statuses: Row[];
    categories: Category[];
}) {
    const [editing, setEditing] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);
    const [name, setName] = useState('');
    const [category, setCategory] = useState<StatusCategory>('unstarted');
    const [color, setColor] = useState(PALETTE[1]);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));

    function onDragEnd(event: DragEndEvent) {
        const { active, over } = event;
        if (!over || active.id === over.id) return;

        const ids = statuses.map((s) => s.id);
        const from = ids.indexOf(Number(active.id));
        const to = ids.indexOf(Number(over.id));

        ids.splice(to, 0, ids.splice(from, 1)[0]);

        router.patch(`/projects/${projectSlug}/statuses/order`, { ids }, { preserveScroll: true });
    }

    function add(e: FormEvent) {
        e.preventDefault();
        router.post(
            `/projects/${projectSlug}/statuses`,
            { name, category, color },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAdding(false);
                    setName('');
                },
            },
        );
    }

    return (
        <section className="max-w-3xl">
            <h2 className="text-sm font-semibold text-ink">Workflow</h2>
            <p className="mt-1 text-sm text-pretty text-ink-muted">
                Rename, recolour and reorder these however suits the project. Each one keeps
                the category it was created with — that is what lets Buggie answer “is this
                still open?” whatever you call your columns.
            </p>

            <DndContext sensors={sensors} onDragEnd={onDragEnd} modifiers={[restrictToVerticalAxis]}>
                <SortableContext items={statuses.map((s) => s.id)} strategy={verticalListSortingStrategy}>
                    <ul className="mt-4 divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                        {statuses.map((status) => (
                            <SortableRow
                                key={status.id}
                                status={status}
                                statuses={statuses}
                                editing={editing === status.id}
                                onEdit={(s) => setEditing(s.id)}
                                onCancel={() => setEditing(null)}
                            />
                        ))}
                    </ul>
                </SortableContext>
            </DndContext>

            {adding ? (
                <form onSubmit={add} className="mt-3 flex flex-wrap items-end gap-3 rounded-xl border border-border bg-raised p-3">
                    <div className="min-w-40 flex-1">
                        <Field label="Name">
                            <Input
                                value={name}
                                autoFocus
                                required
                                maxLength={40}
                                placeholder="Awaiting client"
                                onChange={(e) => setName(e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field
                        label="Behaves like"
                        hint="Fixed once created."
                    >
                        <select
                            value={category}
                            onChange={(e) => setCategory(e.target.value as StatusCategory)}
                            className="h-[38px] rounded-lg border border-border-strong bg-raised px-2 text-sm text-ink focus:border-accent focus:outline-none"
                        >
                            {categories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label} — {c.open ? 'open' : 'closed'}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <fieldset className="flex items-center gap-1 pb-2">
                        <legend className="sr-only">Colour</legend>
                        {PALETTE.map((c) => (
                            <button
                                key={c}
                                type="button"
                                aria-label={`Colour ${c}`}
                                aria-pressed={color === c}
                                onClick={() => setColor(c)}
                                className={`size-5 rounded-full transition ${
                                    color === c ? 'ring-2 ring-accent ring-offset-2 ring-offset-raised' : ''
                                }`}
                                style={{ backgroundColor: c }}
                            />
                        ))}
                    </fieldset>

                    <Button type="submit" disabled={!name}>
                        Add
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => setAdding(false)}>
                        Cancel
                    </Button>
                </form>
            ) : (
                <Button size="sm" variant="secondary" className="mt-3" onClick={() => setAdding(true)}>
                    <Plus className="size-4" />
                    Add status
                </Button>
            )}
        </section>
    );
}
