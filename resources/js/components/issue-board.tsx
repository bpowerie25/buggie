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
    MouseSensor,
    TouchSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Pin, Plus } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type FormEvent, type RefObject } from 'react';

/**
 * Which columns are on screen, and whether there is more to either side.
 *
 * Scrollbars are hidden until you scroll on a Mac and on most phones, so a board
 * wider than the screen gave no sign that it was: the columns to the right simply
 * did not exist as far as anybody could tell. Everything that says "there is more
 * this way" — the fades, the arrows, the strip, the count — reads from here.
 */
function useColumnsInView(scroller: RefObject<HTMLDivElement | null>, columnCount: number) {
    const [state, setState] = useState({ canLeft: false, canRight: false, visible: [] as string[] });

    const measure = useCallback(() => {
        const el = scroller.current;
        if (!el) return;

        const box = el.getBoundingClientRect();
        const visible = [...el.querySelectorAll<HTMLElement>('[data-column]')]
            .filter((column) => {
                const r = column.getBoundingClientRect();
                const shown = Math.min(r.right, box.right) - Math.max(r.left, box.left);

                // Mostly on screen counts; a sliver at the edge does not.
                return shown >= r.width * 0.6;
            })
            .map((column) => column.dataset.column ?? '');

        setState({
            canLeft: el.scrollLeft > 4,
            canRight: el.scrollLeft + el.clientWidth < el.scrollWidth - 4,
            visible,
        });
    }, [scroller]);

    useEffect(() => {
        const el = scroller.current;
        if (!el) return;

        measure();
        el.addEventListener('scroll', measure, { passive: true });
        const resize = new ResizeObserver(measure);
        resize.observe(el);

        return () => {
            el.removeEventListener('scroll', measure);
            resize.disconnect();
        };
    }, [measure, scroller, columnCount]);

    return state;
}

function Card({
    issue,
    dragging,
    onTogglePin,
}: {
    issue: IssueRow;
    dragging?: boolean;
    onTogglePin?: (issue: IssueRow) => void;
}) {
    return (
        <div
            className={`group rounded-lg border bg-raised p-2.5 ${
                issue.pinned ? 'border-accent/50' : 'border-border'
            } ${dragging ? 'rotate-1 shadow-lg' : ''}`}
        >
            <div className="flex items-center gap-1.5">
                <TypeIcon type={issue.type} className="size-3.5" />
                {/* The key opens the issue; the rest of the card drags. A drag only
                    starts after a few pixels of travel, so a click here is a click. */}
                <Link
                    href={`/issues/${issue.key}`}
                    className="font-mono text-[11px] text-ink-subtle hover:text-accent hover:underline"
                >
                    {issue.key}
                </Link>
                {onTogglePin ? (
                    <button
                        type="button"
                        onClick={() => onTogglePin(issue)}
                        aria-label={issue.pinned ? `Unpin ${issue.key}` : `Pin ${issue.key} to the board`}
                        title={issue.pinned ? 'Pinned: always on the board. Click to unpin.' : 'Pin: always show on the board'}
                        className={`rounded p-0.5 transition ${
                            issue.pinned
                                ? 'text-accent'
                                : // No hover on a touch screen, so there it is always shown.
                                  'text-ink-subtle opacity-0 group-hover:opacity-100 focus:opacity-100 [@media(hover:none)]:opacity-100'
                        }`}
                    >
                        <Pin className="size-3" />
                    </button>
                ) : (
                    issue.pinned && <Pin aria-label="Pinned" className="size-3 text-accent" />
                )}
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
    onTogglePin,
}: {
    issue: IssueRow;
    column: string;
    editable: boolean;
    onTogglePin?: (issue: IssueRow) => void;
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
            <Card issue={issue} onTogglePin={onTogglePin} />
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

/** Where a card added to a column can go: the projects that have this status. */
export interface AddTarget {
    projectId: number;
    projectName: string;
}

function Column({
    name,
    status,
    issues,
    editable,
    addTargets,
    onAdd,
    onTogglePin,
}: {
    name: string;
    status: IssueStatus | null;
    issues: IssueRow[];
    editable: boolean;
    addTargets: AddTarget[];
    onAdd?: (column: string, title: string, projectId: number) => void;
    onTogglePin?: (issue: IssueRow) => void;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: name, disabled: !editable });

    return (
        <section
            ref={setNodeRef}
            data-column={name}
            /*
             * A phone shows one column at a time, nearly full width, and snaps to the
             * next; a laptop a fixed width each; a wide screen shares the width out
             * rather than leaving half of it empty to the right.
             */
            className={`flex w-[85vw] max-w-sm shrink-0 snap-center flex-col rounded-xl border p-2 transition sm:w-72 2xl:w-auto 2xl:min-w-72 2xl:flex-1 ${
                isOver ? 'border-accent bg-accent-soft/30' : 'border-border bg-surface'
            }`}
        >
            <header className="flex items-center gap-2 px-1.5 py-1">
                {status && <StatusDot status={status} />}
                <h2 className="text-xs font-medium text-ink">{name}</h2>
                <WipCount count={issues.length} limit={status?.wip_limit ?? null} />
            </header>

            {/* Each column scrolls on its own, so a long one does not push the board off
                the bottom of the screen and take every other column with it. */}
            <div className="mt-1 flex max-h-[calc(100dvh-15rem)] flex-1 flex-col gap-1.5 overflow-y-auto">
                {issues.map((issue) => (
                    <DraggableCard
                        key={issue.id}
                        issue={issue}
                        column={name}
                        editable={editable}
                        onTogglePin={onTogglePin}
                    />
                ))}

                {issues.length === 0 && (
                    <p className="px-1.5 py-3 text-center text-[11px] text-ink-subtle">Nothing here</p>
                )}
            </div>

            {onAdd && addTargets.length > 0 && <AddCard column={name} targets={addTargets} onAdd={onAdd} />}
        </section>
    );
}

/**
 * A card straight into a column: type a title, press Enter. Asks which project only
 * when more than one project on the board has this status.
 */
function AddCard({
    column,
    targets,
    onAdd,
}: {
    column: string;
    targets: AddTarget[];
    onAdd: (column: string, title: string, projectId: number) => void;
}) {
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [projectId, setProjectId] = useState(targets[0]?.projectId ?? 0);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (title.trim() === '') return;

        onAdd(column, title.trim(), projectId);
        setTitle('');
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="mt-1.5 flex items-center gap-1 rounded-md px-1.5 py-1 text-xs text-ink-subtle transition hover:bg-raised hover:text-ink"
            >
                <Plus className="size-3.5" />
                Add card
            </button>
        );
    }

    return (
        <form onSubmit={submit} className="mt-1.5 space-y-1.5">
            <textarea
                value={title}
                autoFocus
                rows={2}
                maxLength={255}
                aria-label={`New card in ${column}`}
                placeholder="What needs doing?"
                onChange={(e) => setTitle(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) submit(e);
                    if (e.key === 'Escape') setOpen(false);
                }}
                className="w-full resize-none rounded-lg border border-border-strong bg-raised px-2 py-1.5 text-xs text-ink focus:border-accent focus:outline-none"
            />
            {targets.length > 1 && (
                <select
                    value={projectId}
                    aria-label="Project"
                    onChange={(e) => setProjectId(Number(e.target.value))}
                    className="w-full rounded-lg border border-border-strong bg-raised px-2 py-1 text-xs text-ink"
                >
                    {targets.map((target) => (
                        <option key={target.projectId} value={target.projectId}>
                            {target.projectName}
                        </option>
                    ))}
                </select>
            )}
            <div className="flex gap-1.5">
                <button type="submit" className="rounded-md bg-accent px-2 py-1 text-xs font-medium text-white">
                    Add
                </button>
                <button
                    type="button"
                    onClick={() => setOpen(false)}
                    className="rounded-md px-2 py-1 text-xs text-ink-muted hover:text-ink"
                >
                    Cancel
                </button>
            </div>
        </form>
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
    onAdd,
    addTargets,
    onTogglePin,
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
    /** Adding a card straight into a column. Absent where adding makes no sense. */
    onAdd?: (column: string, title: string, projectId: number) => void;
    /** Which projects a card added to each column could belong to. */
    addTargets?: (column: string) => AddTarget[];
    onTogglePin?: (issue: IssueRow) => void;
}) {
    const [dragging, setDragging] = useState<IssueRow | null>(null);
    const sensors = useSensors(
        // A mouse: a few pixels of travel before dragging starts, so a click clicks.
        useSensor(MouseSensor, { activationConstraint: { distance: 4 } }),
        // A finger: press and hold. Otherwise every swipe across the board to reach
        // the next column picks up whichever card it started on.
        useSensor(TouchSensor, { activationConstraint: { delay: 250, tolerance: 8 } }),
    );
    const scroller = useRef<HTMLDivElement>(null);
    const strip = useRef<HTMLDivElement>(null);
    const { canLeft, canRight, visible } = useColumnsInView(scroller, columns.length);
    const overflowing = canLeft || canRight;

    function jumpTo(name: string) {
        scroller.current
            ?.querySelector(`[data-column="${CSS.escape(name)}"]`)
            ?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }

    /** One column along, whatever width columns are at this screen size. */
    function step(direction: -1 | 1) {
        const el = scroller.current;
        const column = el?.querySelector<HTMLElement>('[data-column]');
        if (!el || !column) return;

        el.scrollBy({ left: direction * (column.offsetWidth + 12), behavior: 'smooth' });
    }

    // Keep the highlighted part of the strip in view as the board scrolls under it.
    useEffect(() => {
        const first = visible[0];
        if (!first) return;

        strip.current
            ?.querySelector(`[data-chip="${CSS.escape(first)}"]`)
            ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [visible]);

    const indices = visible.map((name) => columns.findIndex((c) => c.name === name)).filter((i) => i >= 0);
    const inView =
        indices.length === 0
            ? ''
            : indices.length === 1
              ? `Column ${indices[0] + 1} of ${columns.length}`
              : `Columns ${Math.min(...indices) + 1}–${Math.max(...indices) + 1} of ${columns.length}`;

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
            {/*
                Every column, with the ones on screen highlighted: a map of the board
                that says how much of it you are looking at, and a way to get to the
                rest. Only when the board does not fit — otherwise it says nothing
                the columns do not.
            */}
            {overflowing && (
                <div className="mb-3 flex items-center gap-3">
                    <nav
                        ref={strip}
                        aria-label="Columns"
                        className="-mx-4 flex min-w-0 flex-1 gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:px-0"
                    >
                        {columns.map(({ name, issues }) => {
                            const shown = visible.includes(name);

                            return (
                                <button
                                    key={name}
                                    type="button"
                                    data-chip={name}
                                    aria-current={shown ? 'true' : undefined}
                                    onClick={() => jumpTo(name)}
                                    className={`shrink-0 rounded-full border px-2.5 py-1 text-xs transition ${
                                        shown
                                            ? 'border-accent/40 bg-accent-soft text-accent'
                                            : 'border-border bg-surface text-ink-muted hover:text-ink'
                                    }`}
                                >
                                    {name} <span className={shown ? '' : 'text-ink-subtle'}>{issues.length}</span>
                                </button>
                            );
                        })}
                    </nav>
                    <span className="hidden shrink-0 text-[11px] text-ink-subtle sm:inline">{inView}</span>
                </div>
            )}

            <div className="relative">
                {/* Fades say "more this way"; the arrows act on it. Both disappear at
                    the end they point at. */}
                <div
                    aria-hidden
                    className={`pointer-events-none absolute inset-y-0 left-0 z-10 w-10 bg-linear-to-r from-canvas to-transparent transition-opacity ${
                        canLeft ? 'opacity-100' : 'opacity-0'
                    }`}
                />
                <div
                    aria-hidden
                    className={`pointer-events-none absolute inset-y-0 right-0 z-10 w-10 bg-linear-to-l from-canvas to-transparent transition-opacity ${
                        canRight ? 'opacity-100' : 'opacity-0'
                    }`}
                />
                {canLeft && (
                    <button
                        type="button"
                        onClick={() => step(-1)}
                        aria-label="Previous column"
                        className="absolute top-24 left-1 z-20 hidden size-9 items-center justify-center rounded-full border border-border bg-raised text-ink-muted shadow-md transition hover:text-ink sm:flex"
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                )}
                {canRight && (
                    <button
                        type="button"
                        onClick={() => step(1)}
                        aria-label="Next column"
                        className="absolute top-24 right-1 z-20 hidden size-9 items-center justify-center rounded-full border border-border bg-raised text-ink-muted shadow-md transition hover:text-ink sm:flex"
                    >
                        <ChevronRight className="size-5" />
                    </button>
                )}

                <div
                    ref={scroller}
                    tabIndex={0}
                    role="region"
                    aria-label={`Board, ${inView || `${columns.length} columns`}. Use the arrow keys to move between columns.`}
                    onKeyDown={(e) => {
                        // Only the board itself: arrow keys in the add-card box move the caret.
                        if (e.target !== e.currentTarget) return;
                        if (e.key === 'ArrowRight') step(1);
                        if (e.key === 'ArrowLeft') step(-1);
                    }}
                    className="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 sm:mx-0 sm:snap-none sm:px-0"
                >
                    {columns.map(({ name, status, issues }) => (
                        <Column
                            key={name}
                            name={name}
                            status={status}
                            editable={editable}
                            issues={issues}
                            addTargets={addTargets?.(name) ?? []}
                            onAdd={onAdd}
                            onTogglePin={onTogglePin}
                        />
                    ))}
                </div>
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
