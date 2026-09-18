import { Button } from '@/components/button';
import { AuthLayout } from '@/layouts/auth-layout';
import type { WorkspaceListing } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

export default function Workspaces({
    workspaces,
}: {
    workspaces: WorkspaceListing[];
}) {
    return (
        <AuthLayout title="Choose a workspace">
            <Head title="Workspaces" />

            <ul className="space-y-1.5">
                {workspaces.map((w) => (
                    <li key={w.slug}>
                        <a
                            href={w.url}
                            className="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5 transition hover:border-border-strong hover:bg-surface"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-ink">
                                    {w.name}
                                </p>
                                <p className="truncate font-mono text-xs text-ink-subtle">
                                    {w.slug}
                                </p>
                            </div>
                            <span className="shrink-0 text-xs text-ink-subtle capitalize">
                                {w.role}
                            </span>
                            <ChevronRight className="size-4 shrink-0 text-ink-subtle" />
                        </a>
                    </li>
                ))}
            </ul>

            <Link href="/workspaces/create" className="mt-4 block">
                <Button variant="secondary" className="w-full">
                    Create a workspace
                </Button>
            </Link>
        </AuthLayout>
    );
}
