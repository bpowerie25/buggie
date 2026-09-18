import { usePage } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import type { SharedProps } from '@/types';

export function Flash() {
    const { flash } = usePage<SharedProps>().props;
    const message = flash.success ?? flash.error;

    if (!message) return null;

    const isError = Boolean(flash.error);
    const Icon = isError ? XCircle : CheckCircle2;

    return (
        <div
            role="status"
            className={`mb-6 flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                isError
                    ? 'border-danger/30 bg-danger-soft text-danger'
                    : 'border-border bg-surface text-ink'
            }`}
        >
            <Icon className={`size-4 ${isError ? '' : 'text-success'}`} />
            {message}
        </div>
    );
}
