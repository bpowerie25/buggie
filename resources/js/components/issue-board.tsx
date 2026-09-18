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
 * Kanban grouped by status name.
 *
 * Statuses belong to projects, so a board spanning projects merges columns by name and
 * a drop resolves to the status with that name *in the dropped issue's own project*.
 * That keeps a cross-project board usable without pretending every project shares one
 * workflow.
 */
export function IssueBoard({
    issues,
    columns,
    statusesByProject,
    editable,
    onMove,
}: {
    issues: IssueRow[];
    columns: { name: string; status: IssueStatus | null }[];
    statusesByProject: Record<number, IssueStatus[]>;
    editable: boolean;
    onMove: (issue: IssueRow, statusId: number) => void;
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

        if (!issue || !columnName || issue.status.name === columnName) return;

        const target = (statusesByProject[issue.project.id] ?? []).find(
            (status) => status.name === columnName,
        );

        // A project without a status of that name simply cannot accept the drop.
        if (target) onMove(issue, target.id);
    }

    return (
        <DndContext sensors={sensors} onDragStart={onDragStart} onDragEnd={onDragEnd}>
            <div className="flex gap-3 overflow-x-auto pb-4">
                {columns.map(({ name, status }) => (
                    <Column
                        key={name}
                        name={name}
                        status={status}
                        editable={editable}
                        issues={issues.filter((issue) => issue.status.name === name)}
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
