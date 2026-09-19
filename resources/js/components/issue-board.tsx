import { Avatar, LabelPill, PriorityBars, StatusDot, TypeIcon } from '@/components/issue-bits';
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

function DraggableCard({ issue, editable }: { issue: IssueRow; editable: boolean }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: issue.key,
        disabled: !editable,
        data: { issue },
    });

    return (
        <div
            ref={setNodeRef}
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
                <span className="text-xs text-ink-subtle">{issues.length}</span>
            </header>

            <div className="mt-1 flex flex-1 flex-col gap-1.5 overflow-y-auto">
                {issues.map((issue) => (
                    <DraggableCard key={issue.id} issue={issue} editable={editable} />
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
    onDropInColumn: (issue: IssueRow, columnName: string) => void;
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
        const columnName = event.over?.id as string | undefined;

        if (!issue || !columnName) return;

        onDropInColumn(issue, columnName);
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
