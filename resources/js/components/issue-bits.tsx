import { cn } from '@/lib/utils';
import type { IssueStatus, IssueTypeValue, LabelChip } from '@/types';
import { Bug, CircleHelp, ListTodo, Sparkles } from 'lucide-react';

const typeIcons: Record<IssueTypeValue, typeof Bug> = {
    bug: Bug,
    feature: Sparkles,
    task: ListTodo,
    question: CircleHelp,
};

export function TypeIcon({
    type,
    className,
}: {
    type: IssueTypeValue;
    className?: string;
}) {
    const Icon = typeIcons[type] ?? Bug;

    return <Icon className={cn('size-4 text-ink-subtle', className)} aria-label={type} />;
}

/** Four bars, filled to the priority level — readable without colour alone. */
export function PriorityBars({
    priority,
    color,
    label,
}: {
    priority: number;
    color: string;
    label: string;
}) {
    return (
        <span className="inline-flex items-end gap-px" title={label} aria-label={label}>
            {[1, 2, 3, 4].map((level) => (
                <span
                    key={level}
                    className="w-1 rounded-[1px]"
                    style={{
                        height: `${4 + level * 2}px`,
                        backgroundColor:
                            priority >= level ? color : 'var(--color-border-strong)',
                    }}
                />
            ))}
        </span>
    );
}

export function StatusDot({ status }: { status: IssueStatus }) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-block size-2.5 shrink-0 rounded-full',
                !status.open && 'ring-2 ring-inset ring-canvas',
            )}
            style={{ backgroundColor: status.color }}
        />
    );
}

export function LabelPill({ label }: { label: LabelChip }) {
    return (
        <span
            className="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[11px] whitespace-nowrap"
            style={{ borderColor: label.color + '66', color: label.color }}
        >
            <span
                aria-hidden
                className="size-1.5 rounded-full"
                style={{ backgroundColor: label.color }}
            />
            {label.name}
        </span>
    );
}

export function Avatar({ name, size = 'sm' }: { name: string; size?: 'sm' | 'md' }) {
    const initials = name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

    return (
        <span
            title={name}
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full bg-accent-soft font-semibold text-accent',
                size === 'sm' ? 'size-5 text-[9px]' : 'size-7 text-[11px]',
            )}
        >
            {initials}
        </span>
    );
}

export function relativeTime(iso: string): string {
    const diff = Date.now() - new Date(iso).getTime();
    const minutes = Math.round(diff / 60000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.round(hours / 24);
    if (days < 30) return `${days}d ago`;

    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** A client has answered and nobody on the team has looked since. Staff only. */
export function ClientRepliedBadge() {
    return (
        <span className="shrink-0 rounded bg-success/10 px-1.5 py-0.5 text-[10px] font-medium text-success">
            Client replied
        </span>
    );
}
