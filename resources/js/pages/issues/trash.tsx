import { AppLayout } from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { RotateCcw, Trash2 } from 'lucide-react';

interface TrashedIssue {
    key: string;
    title: string;
    project: string | null;
    status: string | null;
    deleted_at: string | null;
}

/**
 * What has been deleted, and the way back.
 *
 * Restoring is one click and needs no confirmation — the worst case is an issue
 * reappearing. Deleting for good asks, because that is the only irreversible button
 * in the product.
 */
export default function IssueTrash({ issues }: { issues: TrashedIssue[] }) {
    return (
        <AppLayout title="Deleted issues">
            <Head title="Deleted issues" />

            <div className="max-w-4xl space-y-4">
                <p className="text-sm text-ink-muted">
                    Deleted issues are kept and can be brought back. Their comments,
                    attachments and history come back with them.
                </p>

                {issues.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-ink-subtle">
                        Nothing has been deleted.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="border-b border-border text-left text-xs text-ink-subtle">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Issue</th>
                                    <th className="px-3 py-2 font-medium">Project</th>
                                    <th className="px-3 py-2 font-medium">Deleted</th>
                                    <th className="w-24" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {issues.map((issue) => (
                                    <tr key={issue.key}>
                                        <td className="px-3 py-2">
                                            <span className="font-mono text-xs text-ink-subtle">
                                                {issue.key}
                                            </span>
                                            <span className="ml-2 text-ink">{issue.title}</span>
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-ink-muted">
                                            {issue.project}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-xs text-ink-subtle">
                                            {issue.deleted_at?.slice(0, 10)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <div className="flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    aria-label={`Restore ${issue.key}`}
                                                    title="Bring it back"
                                                    className="rounded p-1 text-ink-subtle transition hover:text-accent"
                                                    onClick={() =>
                                                        router.post(
                                                            `/issues/trash/${issue.key}/restore`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    <RotateCcw className="size-4" />
                                                </button>

                                                <button
                                                    type="button"
                                                    aria-label={`Delete ${issue.key} permanently`}
                                                    title="Delete permanently"
                                                    className="rounded p-1 text-ink-subtle transition hover:text-danger"
                                                    onClick={() => {
                                                        // The only irreversible button
                                                        // in the product, so it asks.
                                                        if (
                                                            !confirm(
                                                                `Delete ${issue.key} permanently? Its comments, attachments and history go with it, and this cannot be undone.`,
                                                            )
                                                        ) {
                                                            return;
                                                        }

                                                        router.delete(
                                                            `/issues/trash/${issue.key}`,
                                                            { preserveScroll: true },
                                                        );
                                                    }}
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
