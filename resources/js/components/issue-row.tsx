import {
    Avatar,
    LabelPill,
    PriorityBars,
    StatusDot,
    TypeIcon,
    relativeTime,
} from '@/components/issue-bits';
import { Popover, PopoverItem } from '@/components/popover';
import type { Facets, IssueRow as Row, IssueStatus } from '@/types';
import type { RequestPayload } from '@inertiajs/core';
import { Link, router } from '@inertiajs/react';

/**
 * One row in the issue list. Status, assignee and priority are editable in place:
 * the PATCH returns `back()`, so Inertia reloads only the issues prop and the row
 * updates without navigating.
 */
export function IssueRow({
    issue,
    statuses,
    facets,
    editable,
    showProject,
}: {
    issue: Row;
    statuses: IssueStatus[];
    facets: Facets;
    editable: boolean;
    showProject: boolean;
}) {
    function patch(payload: RequestPayload) {
        router.patch(`/issues/${issue.key}`, payload, {
            preserveScroll: true,
            preserveState: true,
            only: ['issues', 'flash'],
        });
    }

    return (
        <div className="flex items-center gap-3 px-4 py-2 transition hover:bg-surface">
            {editable ? (
                <Popover
                    label={`Status: ${issue.status.name}`}
                    trigger={() => (
                        <span className="p-1">
                            <StatusDot status={issue.status} />
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
                                    if (status.id !== issue.status.id) {
                                        patch({ status_id: status.id });
                                    }
                                }}
                            >
                                <StatusDot status={status} />
                                <span className="truncate">{status.name}</span>
                            </PopoverItem>
                        ))
                    }
                </Popover>
            ) : (
                <span className="p-1">
                    <StatusDot status={issue.status} />
                </span>
            )}

            <TypeIcon type={issue.type} />

            <span className="w-20 shrink-0 font-mono text-xs text-ink-subtle">
                {issue.key}
            </span>

            <Link
                href={`/issues/${issue.key}`}
                className="min-w-0 flex-1 truncate text-sm text-ink hover:text-accent"
            >
                {issue.title}
            </Link>

            {showProject && (
                <span className="hidden shrink-0 rounded bg-surface px-1.5 py-0.5 text-[11px] text-ink-muted lg:inline">
                    {issue.project.key}
                </span>
            )}

            <span className="hidden shrink-0 items-center gap-1 md:flex">
                {issue.labels.slice(0, 2).map((label) => (
                    <LabelPill key={label.id} label={label} />
                ))}
                {issue.labels.length > 2 && (
                    <span className="text-[11px] text-ink-subtle">
                        +{issue.labels.length - 2}
                    </span>
                )}
            </span>

            {editable ? (
                <Popover
                    align="right"
                    label={`Priority: ${issue.priority_label}`}
                    trigger={() => (
                        <span className="px-1.5 py-1">
                            <PriorityBars
                                priority={issue.priority}
                                color={issue.priority_color}
                                label={issue.priority_label}
                            />
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
                                    if (option.value !== issue.priority) {
                                        patch({ priority: option.value });
                                    }
                                }}
                            >
                                <PriorityBars
                                    priority={option.value}
                                    color={option.color}
                                    label={option.label}
                                />
                                <span className="truncate">{option.label}</span>
                            </PopoverItem>
                        ))
                    }
                </Popover>
            ) : (
                <PriorityBars
                    priority={issue.priority}
                    color={issue.priority_color}
                    label={issue.priority_label}
                />
            )}

            <span className="hidden w-16 shrink-0 text-right text-[11px] text-ink-subtle sm:inline">
                {relativeTime(issue.updated_at)}
            </span>

            {editable ? (
                <Popover
                    align="right"
                    label={`Assignee: ${issue.assignee?.name ?? 'unassigned'}`}
                    trigger={() => (
                        <span className="p-0.5">
                            {issue.assignee ? (
                                <Avatar name={issue.assignee.name} />
                            ) : (
                                <span className="inline-block size-5 rounded-full border border-dashed border-border-strong" />
                            )}
                        </span>
                    )}
                >
                    {(close) => (
                        <>
                            <PopoverItem
                                selected={issue.assignee === null}
                                onSelect={() => {
                                    close();
                                    patch({ assignee_id: null });
                                }}
                            >
                                <span className="inline-block size-5 rounded-full border border-dashed border-border-strong" />
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
                                    <span className="truncate">{member.name}</span>
                                </PopoverItem>
                            ))}
                        </>
                    )}
                </Popover>
            ) : (
                issue.assignee && <Avatar name={issue.assignee.name} />
            )}
        </div>
    );
}
