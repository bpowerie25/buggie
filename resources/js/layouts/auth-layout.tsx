import { Link } from '@inertiajs/react';
import { Bug } from 'lucide-react';
import type { ReactNode } from 'react';

export function AuthLayout({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-surface px-4 py-12">
            <div className="w-full max-w-sm">
                <Link
                    href="/"
                    className="mb-8 flex items-center justify-center gap-2 text-ink"
                >
                    <Bug className="size-6 text-accent" />
                    <span className="text-lg font-semibold tracking-tight">Buggie</span>
                </Link>

                <div className="rounded-xl border border-border bg-raised p-6 shadow-sm">
                    <h1 className="text-lg font-semibold text-ink">{title}</h1>
                    {description && (
                        <p className="mt-1 text-sm text-ink-muted">{description}</p>
                    )}
                    <div className="mt-6">{children}</div>
                </div>
            </div>
        </div>
    );
}
