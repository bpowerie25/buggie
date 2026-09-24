import {
    Avatar,
    ClientRepliedBadge,
    LabelPill,
    PriorityBars,
    StatusDot,
    TypeIcon,
} from '@/components/issue-bits';
import type { IssueRow, IssueStatus } from '@/types';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

function Card({ issue, dragging }: { issue: IssueRow; dragging?: boolean }) {
    return (
        <div
            className={`rounded-lg border border-border bg-raised p-2.5 ${
                dragging ? 'rotate-1 shadow-lg' : ''
            }`}
        >
            <div className="flex items-center gap-1.5">
                <TypeIcon type={issue.type} className="size-3.5" />
                <span className="font-mono text-[11px] text-ink-subtle">{issue.key}</span>
                <span className="ml-auto">
                    <PriorityBars
                        priority={issue.priority}
                        color={issue.priority_color}
                        label={issue.priority_label}
                    />
                </span>
            </div>

            <p className="mt-1.5 line-clamp-3 text-xs text-ink">{issue.title}</p>
            {issue.client_replied && (
                <div className="mt-1.5">
                    <ClientRepliedBadge />
                </div>
            )}

            {(issue.labels.length > 0 || issue.assignee) && (
                <div className="mt-2 flex items-center gap-1">
                    {issue.labels.slice(0, 2).map((label) => (
                        <LabelPill key={label.id} label={label} />
                    ))}
                    {issue.assignee && (
                        <span className="ml-auto">
                            <Avatar name={issue.assignee.name} />
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

function DraggableCard({
    issue,
    column,
    editable,
}: {
    issue: IssueRow;
    column: string;
    editable: boolean;
}) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: issue.key,
        disabled: !editable,
        data: { issue },
    });

    /*
     * Every card is also a drop target, which is what gives a drop a position rather
     * than only a column. Dropping on the column's own area still works and means
     * "the end", so there is always somewhere to aim at in an empty column.
     */
    const { setNodeRef: setDropRef } = useDroppable({
        id: `card:${issue.key}`,
        disabled: !editable,
        data: { issue, column },
    });

    return (
        <div
            ref={(node) => {
                setNodeRef(node);
                setDropRef(node);
            }}
            {...listeners}
            {...attributes}
            className={`${isDragging ? 'opacity-40' : ''} ${editable ? 'cursor-grab active:cursor-grabbing' : ''}`}
        >
            {/* The whole card drags; the key is the click target, so dragging never
                fights with navigating. */}
            <Card issue={issue} />
            <Link
                href={`/issues/${issue.key}`}
                className="sr-only"
                aria-label={`Open ${issue.key}`}
            />
        </div>
    );
}

/**
 * How full a column is.
 *
 * Nothing stops a drop that takes a column over its limit. A limit that refuses
 * turns "finish something before starting another" into an obstacle to be worked
 * around, usually by abandoning the board — the mechanism is that everybody looking
 * at the same screen can see the number has gone red.
 */
function WipCount({ count, limit }: { count: number; limit: number | null }) {
    if (limit === null) {
        return <span className="text-xs text-ink-subtle">{count}</span>;
    }

    const over = count > limit;
    const full = count === limit;

    return (
        <span
            title={
                over
                    ? `Over the limit of ${limit} for this column`
                    : `${count} of ${limit} allowed in this column`
            }
            className={`rounded px-1.5 py-0.5 text-xs tabular-nums ${
                over
                    ? 'bg-danger/15 font-medium text-danger'
                    : full
                      ? 'bg-amber-500/15 font-medium text-amber-600 dark:text-amber-500'
                      : 'text-ink-subtle'
            }`}
        >
            {count}/{limit}
        </span>
    );
}

function Column({
    name,
    status,
    issues,
    editable,
}: {
    name: string;
    status: IssueStatus | null;
    issues: IssueRow[];
    editable: boolean;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: name, disabled: !editable });

    return (
        <section
            ref={setNodeRef}
            className={`flex w-72 shrink-0 flex-col rounded-xl border p-2 transition ${
                isOver ? 'border-accent bg-accent-soft/30' : 'border-border bg-surface'
            }`}
        >
            <header className="flex items-center gap-2 px-1.5 py-1">
                {status && <StatusDot status={status} />}
                <h2 className="text-xs font-medium text-ink">{name}</h2>
                <WipCount count={issues.length} limit={status?.wip_limit ?? null} />
            </header>

            <div className="mt-1 flex flex-1 flex-col gap-1.5 overflow-y-auto">
                {issues.map((issue) => (
                    <DraggableCard
                        key={issue.id}
                        issue={issue}
                        column={name}
                        editable={editable}
                    />
                ))}
            </div>
        </section>
    );
}

/**
 * Kanban, grouped by whatever the list is grouped by.
 *
 * The board knows nothing about what a column means. It reports which column a card
 * was dropped on and lets the page decide what that implies — a status on a status
 * board, an assignee on an assignee board.
 *
 * It used to resolve the drop to a status by name regardless of the grouping, so on
 * an assignee or priority board the card animated back and nothing happened at all:
 * no change, no error, no explanation. A control that silently does nothing is worse
 * than one that is visibly unavailable, which is why `editable` now covers groupings
 * a drag cannot express.
 */
export function IssueBoard({
    columns,
    editable,
    onDropInColumn,
}: {
    /**
     * Columns arrive with their own cards. The board used to be handed every issue
     * and filter each column by `issue.status.name === column`, which is only ever
     * true on a status board — so an assignee or priority board rendered its columns
     * and then showed nothing in them at all.
     */
    columns: { name: string; status: IssueStatus | null; issues: IssueRow[] }[];
    editable: boolean;
    /**
     * `beforeKey` is the card the dropped one should end up above, or null for the
     * end of the column. The board reports a position; what it means is the page's
     * business.
     */
    onDropInColumn: (issue: IssueRow, columnName: string, beforeKey: string | null) => void;
}) {
    const [dragging, setDragging] = useState<IssueRow | null>(null);
    const sensors = useSensors(
        // A few pixels of travel before dragging starts, so a click still clicks.
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    );

    function onDragStart(event: DragStartEvent) {
        setDragging((event.active.data.current?.issue as IssueRow) ?? null);
    }

    function onDragEnd(event: DragEndEvent) {
        setDragging(null);

        const issue = event.active.data.current?.issue as IssueRow | undefined;
        const over = event.over;

        if (!issue || !over) return;

        const overCard = over.data.current?.issue as IssueRow | undefined;

        // Dropped on the column itself rather than on a card: the end of it.
        if (!overCard) {
            onDropInColumn(issue, String(over.id), null);

            return;
        }

        if (overCard.key === issue.key) return;

        const column = String(over.data.current?.column ?? '');
        const cards = columns.find((c) => c.name === column)?.issues ?? [];

        /*
         * Above or below the card being hovered, decided by where the pointer ended
         * up rather than by always inserting before.
         *
         * `active.rect.current.translated` is where the dragged card actually is on
         * screen; comparing its middle with the middle of the card underneath is the
         * same judgement the person dragging is making by eye.
         */
        const draggedMiddle =
            (event.active.rect.current.translated?.top ?? 0) +
            (event.active.rect.current.translated?.height ?? 0) / 2;

        const overMiddle = over.rect.top + over.rect.height / 2;
        const below = draggedMiddle > overMiddle;

        // The card it should end up sitting above. Null means the end of the column.
        const withoutDragged = cards.filter((c) => c.key !== issue.key);
        const index = withoutDragged.findIndex((c) => c.key === overCard.key);
        const beforeIndex = below ? index + 1 : index;

        onDropInColumn(issue, column, withoutDragged[beforeIndex]?.key ?? null);
    }

    return (
        <DndContext sensors={sensors} onDragStart={onDragStart} onDragEnd={onDragEnd}>
            <div className="flex gap-3 overflow-x-auto pb-4">
                {columns.map(({ name, status, issues }) => (
                    <Column
                        key={name}
                        name={name}
                        status={status}
                        editable={editable}
                        issues={issues}
                    />
                ))}
            </div>

            <DragOverlay dropAnimation={null}>
                {dragging && (
                    <div className="w-72">
                        <Card issue={dragging} dragging />
                    </div>
                )}
            </DragOverlay>
        </DndContext>
    );
}
