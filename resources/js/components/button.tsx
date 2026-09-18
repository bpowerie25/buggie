import { cn } from '@/lib/utils';
import type { ButtonHTMLAttributes } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

const variants: Record<Variant, string> = {
    primary:
        'bg-accent text-accent-ink hover:opacity-90 disabled:opacity-50',
    secondary:
        'bg-raised text-ink border border-border-strong hover:bg-surface disabled:opacity-50',
    ghost: 'text-ink-muted hover:bg-surface hover:text-ink',
    danger: 'bg-danger text-white hover:opacity-90 disabled:opacity-50',
};

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    size?: 'sm' | 'md';
}

export function Button({
    variant = 'primary',
    size = 'md',
    className,
    ...props
}: Props) {
    return (
        <button
            className={cn(
                'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition',
                'disabled:cursor-not-allowed',
                size === 'sm' ? 'h-8 px-3 text-sm' : 'h-10 px-4 text-sm',
                variants[variant],
                className,
            )}
            {...props}
        />
    );
}
