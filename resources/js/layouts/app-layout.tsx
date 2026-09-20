import { CommandPalette } from '@/components/command-palette';
import { Flash } from '@/components/flash';
import { ShortcutSheet } from '@/components/shortcut-sheet';
import { useHotkeys } from '@/hooks/use-hotkeys';
import type { SharedProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Bookmark,
    Bug,
    CircleQuestionMark,
    ChevronsUpDown,
    CircleDot,
    CreditCard,
    FolderKanban,
    Inbox,
    Keyboard,
    LayoutDashboard,
    LogOut,
    Server,
    Settings,
    ShieldCheck,
    ChartLine,
    Clock,
    Tag,
    Users,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

function NavLink({
    href,
    icon: Icon,
    children,
    active,
    badge,
}: {
    href: string;
    icon: typeof Bug;
    children: ReactNode;
    active: boolean;
    badge?: number;
}) {
    return (
        <Link
            href={href}
            className={`flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition ${
                active
                    ? 'bg-accent-soft font-medium text-accent'
                    : 'text-ink-muted hover:bg-surface hover:text-ink'
            }`}
        >
            <Icon className="size-4 shrink-0" />
            <span className="min-w-0 flex-1 truncate">{children}</span>
            {badge ? (
                <span className="shrink-0 rounded-full bg-accent px-1.5 py-0.5 text-[10px] font-semibold text-accent-ink">
                    {badge}
                </span>
            ) : null}
        </Link>
    );
}

export function AppLayout({
    title,
    actions,
    children,
}: {
    title: string;
    actions?: ReactNode;
    /** Accepted for call-site clarity; views and the query come from shared props. */
    views?: unknown;
    activeQuery?: string;
    children: ReactNode;
}) {
    const { auth, workspace, workspaces, views, inboxCount, billing, mail, docsUrl, ziggy } = usePage<
        SharedProps & { ziggy: { location: string } }
    >().props;
    const [switcherOpen, setSwitcherOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const [shortcutsOpen, setShortcutsOpen] = useState(false);

    const url = new URL(ziggy.location);
    const path = url.pathname;
    const currentQuery = url.searchParams.get('q');

    useHotkeys({
        'mod+k': () => setPaletteOpen((open) => !open),
        '?': () => setShortcutsOpen((open) => !open),
        c: () => router.visit('/issues/create'),
        Escape: () => {
            setPaletteOpen(false);
            setShortcutsOpen(false);
        },
        'g i': () => router.visit('/issues'),
        'g p': () => router.visit('/projects'),
        'g t': () => router.visit('/inbox'),
        'g d': () => router.visit('/'),
    });

    return (
        <div className="flex min-h-screen">
            <aside className="flex w-60 shrink-0 flex-col border-r border-border bg-surface">
                <div className="relative p-3">
                    <button
                        onClick={() => setSwitcherOpen((o) => !o)}
                        className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left transition hover:bg-raised"
                    >
                        <Bug className="size-5 shrink-0 text-accent" />
                        <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink">
                            {workspace?.name ?? 'Buggie'}
                        </span>
                        <ChevronsUpDown className="size-3.5 shrink-0 text-ink-subtle" />
                    </button>

                    {switcherOpen && workspaces.length > 0 && (
                        <div className="absolute inset-x-3 top-full z-10 rounded-lg border border-border bg-raised p-1 shadow-lg">
                            {workspaces.map((w) => (
                                <a
                                    key={w.slug}
                                    href={w.url}
                                    className="block truncate rounded-md px-2 py-1.5 text-sm text-ink-muted hover:bg-surface hover:text-ink"
                                >
                                    {w.name}
                                </a>
                            ))}
                        </div>
                    )}
                </div>

                <nav className="flex-1 space-y-0.5 px-3">
                    <NavLink href="/" icon={LayoutDashboard} active={path === '/'}>
                        Dashboard
                    </NavLink>
                    <NavLink
                        href="/issues"
                        icon={CircleDot}
                        active={path.startsWith('/issues')}
                    >
                        Issues
                    </NavLink>
                    {auth.role !== 'client' && (
                        <NavLink
                            href="/inbox"
                            icon={Inbox}
                            active={path.startsWith('/inbox')}
                            badge={inboxCount}
                        >
                            Triage
                        </NavLink>
                    )}
                    <NavLink
                        href="/projects"
                        icon={FolderKanban}
                        active={path.startsWith('/projects')}
                    >
                        Projects
                    </NavLink>
                    <NavLink
                        href="/settings/notifications"
                        icon={Bell}
                        active={path.startsWith('/settings/notifications')}
                    >
                        Notifications
                    </NavLink>
                    {/* Outside the staff-only block below on purpose: a client's
                        account is worth breaking into too. */}
                    <NavLink
                        href="/settings/two-factor"
                        icon={ShieldCheck}
                        active={path.startsWith('/settings/two-factor')}
                    >
                        Two-factor
                    </NavLink>
                    {auth.role !== 'client' && (
                        <>
                            <NavLink
                                href="/labels"
                                icon={Tag}
                                active={path.startsWith('/labels')}
                            >
                                Labels
                            </NavLink>
                            {/* Staff only, like everything in this block: how long
                                something took is not a client's business. */}
                            <NavLink
                                href="/insights"
                                icon={ChartLine}
                                active={path.startsWith('/insights')}
                            >
                                Insights
                            </NavLink>
                            <NavLink href="/time" icon={Clock} active={path.startsWith('/time')}>
                                Time
                            </NavLink>
                            <NavLink
                                href="/settings/members"
                                icon={Users}
                                active={path.startsWith('/settings/members')}
                            >
                                Members
                            </NavLink>
                            <NavLink
                                href="/settings/workspace"
                                icon={Settings}
                                active={path.startsWith('/settings/workspace')}
                            >
                                Settings
                            </NavLink>
                            {auth.operator && (
                                <NavLink
                                    href="/settings/instance"
                                    icon={Server}
                                    active={path.startsWith('/settings/instance')}
                                >
                                    Instance
                                </NavLink>
                            )}
                            {billing?.can_manage && (
                                <NavLink
                                    href="/settings/billing"
                                    icon={CreditCard}
                                    active={path.startsWith('/settings/billing')}
                                >
                                    Billing
                                </NavLink>
                            )}
                        </>
                    )}

                    {views.length > 0 && (
                        <div className="pt-4">
                            <h2 className="px-2.5 pb-1 text-[11px] font-semibold tracking-wide text-ink-subtle uppercase">
                                Views
                            </h2>
                            {views.map((view) => (
                                <Link
                                    key={view.id}
                                    href={`/issues?q=${encodeURIComponent(view.query)}&layout=${view.layout}&group=${view.group_by}`}
                                    className={`flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition ${
                                        currentQuery === view.query
                                            ? 'bg-accent-soft font-medium text-accent'
                                            : 'text-ink-muted hover:bg-surface hover:text-ink'
                                    }`}
                                >
                                    <Bookmark className="size-4 shrink-0" />
                                    <span className="min-w-0 flex-1 truncate">{view.name}</span>
                                    {view.shared && (
                                        <span className="shrink-0 text-[10px] text-ink-subtle">
                                            shared
                                        </span>
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}
                </nav>

                <div className="border-t border-border p-3">
                    <div className="flex items-center gap-2.5 px-1">
                        <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-accent text-[11px] font-semibold text-accent-ink">
                            {auth.user?.initials}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm text-ink">{auth.user?.name}</p>
                            <p className="truncate text-xs text-ink-subtle capitalize">
                                {auth.role}
                            </p>
                        </div>
                        {/*
                            A plain anchor, and a new tab: the guide is on the central
                            domain, so an Inertia visit would leave the workspace, and
                            somebody reading how to do a thing usually wants to keep
                            the thing on screen.
                        */}
                        <a
                            href={docsUrl}
                            target="_blank"
                            rel="noreferrer"
                            aria-label="Help"
                            title="Help"
                            className="rounded-md p-1.5 text-ink-subtle transition hover:bg-raised hover:text-ink"
                        >
                            <CircleQuestionMark className="size-4" />
                        </a>
                        <button
                            onClick={() => setShortcutsOpen(true)}
                            aria-label="Keyboard shortcuts"
                            title="Keyboard shortcuts (?)"
                            className="rounded-md p-1.5 text-ink-subtle transition hover:bg-raised hover:text-ink"
                        >
                            <Keyboard className="size-4" />
                        </button>
                        <button
                            onClick={() => router.post('/logout')}
                            aria-label="Sign out"
                            className="rounded-md p-1.5 text-ink-subtle transition hover:bg-raised hover:text-ink"
                        >
                            <LogOut className="size-4" />
                        </button>
                    </div>
                </div>
            </aside>

            <main className="min-w-0 flex-1">
                <header className="flex h-14 items-center justify-between gap-4 border-b border-border px-6">
                    <h1 className="truncate text-sm font-semibold text-ink">{title}</h1>
                    <div className="flex shrink-0 items-center gap-2">{actions}</div>
                </header>

                <div className="p-6">
                    <MailBanner mail={mail} path={path} />
                    <UsageBanner billing={billing} />
                    <Flash />
                    {children}
                </div>
            </main>

            <CommandPalette open={paletteOpen} onOpenChange={setPaletteOpen} />
            {shortcutsOpen && <ShortcutSheet onClose={() => setShortcutsOpen(false)} />}
        </div>
    );
}

/**
 * Says that mail is going nowhere.
 *
 * Unlike the usage banner this does not go away, because the condition does not go
 * away on its own and every hour it is true is an hour of invitations and password
 * resets vanishing in silence. The wording differs by who is reading: an operator
 * can fix it, and everyone else needs to know to send the link by hand instead.
 */
function MailBanner({ mail, path }: { mail: SharedProps['mail']; path: string }) {
    if (!mail) return null;

    // Not on the page that fixes it: the form is right there, and it carries its own
    // warning that reacts to what is currently typed rather than what is saved.
    if (path.startsWith('/settings/instance')) return null;

    return (
        <div className="mb-6 flex flex-wrap items-center gap-2 rounded-lg border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
            <span>
                {mail.can_fix
                    ? 'Email is not set up, so invitations, password resets and notifications are not being delivered.'
                    : 'Email is not set up on this Buggie, so invitations and notifications are not being delivered. Send people their invitation link yourself.'}
            </span>
            {mail.can_fix && (
                <Link
                    href="/settings/instance"
                    className="ml-auto font-medium underline underline-offset-2"
                >
                    Set up email
                </Link>
            )}
        </div>
    );
}

const LIMIT_LABELS: Record<string, string> = {
    projects: 'projects',
    members: 'people',
    reports_per_month: 'reports this month',
};

/**
 * Says something only when it matters: a limit reached, or a trial about to end.
 * A banner that is always there is a banner nobody reads.
 */
function UsageBanner({ billing }: { billing: SharedProps['billing'] }) {
    if (!billing) return null;

    const breached = Object.entries(billing.usage).find(([, row]) => row.over);
    const approaching = Object.entries(billing.usage).find(([, row]) => row.near && !row.over);
    const trialEnding = billing.on_trial && billing.trial_days_left <= 3;

    if (!breached && !approaching && !trialEnding) return null;

    const message = breached
        ? `You've reached your plan's limit of ${breached[1].limit} ${LIMIT_LABELS[breached[0]] ?? breached[0]}.`
        : approaching
          ? `You've used ${approaching[1].used} of ${approaching[1].limit} ${LIMIT_LABELS[approaching[0]] ?? approaching[0]}.`
          : `Your trial ends in ${billing.trial_days_left} day${billing.trial_days_left === 1 ? '' : 's'}.`;

    return (
        <div
            className={`mb-6 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                breached
                    ? 'border-danger/30 bg-danger-soft text-danger'
                    : 'border-amber-500/40 bg-amber-500/10 text-amber-600 dark:text-amber-500'
            }`}
        >
            <span>{message}</span>
            {billing.can_manage && (
                <Link
                    href="/settings/billing"
                    className="ml-auto font-medium underline underline-offset-2"
                >
                    See plans
                </Link>
            )}
        </div>
    );
}
