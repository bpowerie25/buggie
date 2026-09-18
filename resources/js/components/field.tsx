import { cn } from '@/lib/utils';
import type { InputHTMLAttributes, ReactNode, TextareaHTMLAttributes } from 'react';

const control =
    'w-full rounded-lg border border-border-strong bg-raised px-3 py-2 text-sm text-ink ' +
    'placeholder:text-ink-subtle transition focus:border-accent focus:outline-none ' +
    'focus:ring-2 focus:ring-accent/30 disabled:opacity-60';

export function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: ReactNode;
    children: ReactNode;
}) {
    return (
        <label className="block space-y-1.5">
            <span className="text-sm font-medium text-ink">{label}</span>
            {children}
            {hint && !error && (
                <span className="block text-xs text-ink-muted">{hint}</span>
            )}
            {error && (
                <span role="alert" className="block text-xs text-danger">
                    {error}
                </span>
            )}
        </label>
    );
}

export function Input({
    className,
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return <input className={cn(control, className)} {...props} />;
}

export function Textarea({
    className,
    ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return <textarea className={cn(control, 'resize-y', className)} {...props} />;
}
