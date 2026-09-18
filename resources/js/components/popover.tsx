import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';

/**
 * Small anchored menu used for every inline edit in the issue list. Closes on
 * outside click and on Escape, and returns focus to the trigger so keyboard
 * navigation is not lost.
 */
export function Popover({
    trigger,
    children,
    align = 'left',
    label,
}: {
    trigger: (props: { open: boolean }) => ReactNode;
    children: (close: () => void) => ReactNode;
    align?: 'left' | 'right';
    label: string;
}) {
    const [open, setOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) return;

        function onPointerDown(event: MouseEvent) {
            if (!container.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                setOpen(false);
                triggerRef.current?.focus();
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div ref={container} className="relative">
            <button
                ref={triggerRef}
                type="button"
                aria-label={label}
                aria-expanded={open}
                aria-haspopup="menu"
                onClick={() => setOpen((o) => !o)}
                className="flex items-center rounded-md transition hover:bg-surface focus-visible:bg-surface"
            >
                {trigger({ open })}
            </button>

            {open && (
                <div
                    role="menu"
                    className={cn(
                        'absolute top-full z-30 mt-1 max-h-72 w-56 overflow-y-auto rounded-lg border border-border bg-raised p-1 shadow-lg',
                        align === 'right' ? 'right-0' : 'left-0',
                    )}
                >
                    {children(() => setOpen(false))}
                </div>
            )}
        </div>
    );
}

export function PopoverItem({
    onSelect,
    selected,
    children,
}: {
    onSelect: () => void;
    selected?: boolean;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            role="menuitem"
            onClick={onSelect}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-ink-muted transition hover:bg-surface hover:text-ink"
        >
            <span className="flex min-w-0 flex-1 items-center gap-2">{children}</span>
            {selected && <Check className="size-3.5 shrink-0 text-accent" />}
        </button>
    );
}
