import { Button } from '@/components/button';
import { relativeTime } from '@/components/issue-bits';
import { AppLayout } from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { BellOff, CheckCheck, Settings2 } from 'lucide-react';

interface NotificationRow {
    id: number;
    reason: string;
    label: string;
    sentence: string;
    issue: { key: string; title: string; project: string | null };
    url: string;
    created_at: string;
    read: boolean;
}

/**
 * What has happened to you, newest first.
 *
 * Deliberately not a dropdown behind the bell. A panel that closes when you look
 * away is a panel you read half of, and this is the only place some installs have
 * ever surfaced a notification at all — mail is frequently not set up.
 */
export default function NotificationsIndex({
    notifications,
    unread,
    limit,
}: {
    notifications: NotificationRow[];
    unread: number;
    limit: number;
}) {
    return (
        <AppLayout
            title="Notifications"
            actions={
                <>
                    <Link
                        href="/settings/notifications"
                        className="inline-flex h-8 items-center gap-2 rounded-lg px-3 text-sm text-ink-muted transition hover:bg-surface hover:text-ink"
                    >
                        <Settings2 className="size-4" />
                        Preferences
                    </Link>
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={unread === 0}
                        onClick={() =>
                            router.post('/notifications/read', {}, { preserveScroll: true })
                        }
                    >
                        <CheckCheck className="size-4" />
                        Mark all read
                    </Button>
                </>
            }
        >
            <Head title="Notifications" />

            {notifications.length === 0 ? (
                <div className="rounded-xl border border-border bg-raised px-6 py-12 text-center">
                    <BellOff className="mx-auto size-6 text-ink-subtle" />
                    <p className="mt-3 text-sm text-ink">Nothing has happened to you yet.</p>
                    <p className="mt-1 text-sm text-ink-muted">
                        You hear about issues you report, are assigned, are mentioned in, or
                        watch.
                    </p>
                </div>
            ) : (
                <ul className="max-w-3xl divide-y divide-border overflow-hidden rounded-xl border border-border bg-raised">
                    {notifications.map((entry) => (
                        <Row key={entry.id} entry={entry} />
                    ))}
                </ul>
            )}

            {notifications.length >= limit && (
                <p className="mt-3 max-w-3xl text-xs text-ink-subtle">
                    Showing the most recent {limit}. Anything older is still on the issue
                    itself.
                </p>
            )}
        </AppLayout>
    );
}

function Row({ entry }: { entry: NotificationRow }) {
    const body = (
        <>
            {/* The unread marker is a dot, not a colour on the text: colour alone
                is not a signal for everybody who reads this. */}
            <span
                aria-hidden
                className={`mt-2 size-2 shrink-0 rounded-full ${
                    entry.read ? 'bg-transparent' : 'bg-accent'
                }`}
            />
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-baseline gap-x-2">
                    <span className="font-mono text-xs text-ink-subtle">{entry.issue.key}</span>
                    <span
                        className={`min-w-0 truncate text-sm ${
                            entry.read ? 'text-ink-muted' : 'font-medium text-ink'
                        }`}
                    >
                        {entry.issue.title}
                    </span>
                </span>
                <span className="mt-0.5 block text-sm text-ink-muted">{entry.sentence}</span>
                <span className="mt-0.5 block text-xs text-ink-subtle">
                    {entry.label}
                    {entry.issue.project ? ` · ${entry.issue.project}` : ''} ·{' '}
                    {relativeTime(entry.created_at)}
                    {!entry.read && <span className="sr-only"> · unread</span>}
                </span>
            </span>
        </>
    );

    const className =
        'flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-surface';

    // Read entries have nothing to change, so they stay ordinary links: openable in
    // a new tab, copyable, prefetchable. An unread one posts first and is redirected
    // to the issue, which is what makes "opening it marks it read" true on the
    // server rather than hopefully, in a request that races the navigation.
    return (
        <li>
            {entry.read ? (
                <Link href={entry.url} className={className}>
                    {body}
                </Link>
            ) : (
                <Link
                    href={`/notifications/${entry.id}/read`}
                    method="post"
                    as="button"
                    className={className}
                >
                    {body}
                </Link>
            )}
        </li>
    );
}
