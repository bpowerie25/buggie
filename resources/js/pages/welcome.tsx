import { Button } from '@/components/button';
import { Head, Link } from '@inertiajs/react';
import {
    Bug,
    CalendarClock,
    Camera,
    ChartLine,
    Clock,
    Columns3,
    Check,
    Code2,
    Inbox,
    Layers,
    Mail,
    MessageSquare,
    Server,
    ShieldCheck,
    SlidersHorizontal,
    Smartphone,
    Tag,
    Upload,
    Users,
    Webhook,
} from 'lucide-react';
import type { ReactNode } from 'react';

interface Plan {
    key: string;
    name: string;
    price: string;
    currency: string;
    interval: string;
    interval_suffix: string;
    saving: string | null;
    blurb: string;
    limits: Record<string, number | null>;
    priced: boolean;
    subscribable: boolean;
}

/**
 * Three reasons, not a list of everything.
 *
 * The page sold only the first of these for a long while, which is the weakest of
 * the three: capturing a good report is the visible feature, but being able to put a
 * client in a tracker without putting them in your backlog is the one that decides
 * whether an agency can use this at all.
 */
const features = [
    {
        icon: Camera,
        title: 'Reports arrive complete',
        body: 'A script tag captures the screenshot, console, failing request, route and signed-in user at the moment the bug happens — so nobody has to ask "what browser?" again.',
    },
    {
        icon: Users,
        title: 'Clients, without the mess',
        body: 'Invite a client to their own projects only. Share the issues you choose, keep internal notes internal, and let whoever reported a bug follow it through a private link without an account.',
    },
    {
        icon: Server,
        title: 'It stays yours',
        body: 'Open source and self-hostable, with an API and CSV export. Your clients’ history is not hostage to anybody’s pricing page — including mine.',
    },
];

/** The rest, once somebody is still reading. */
const alsoDoes = [
    {
        icon: Columns3,
        title: 'A board that answers “what next”',
        body: 'Kanban grouped by status, assignee, priority or project. Drag cards into the order you actually mean, and set a limit per column that goes red rather than refusing the drop.',
    },
    {
        icon: Inbox,
        title: 'Triage, then backlog',
        body: 'Reports land in an inbox, not your backlog. Accept, merge, spam or discard in one keystroke each.',
    },
    {
        icon: Layers,
        title: 'Duplicates collapse',
        body: 'Forty people hitting one broken checkout is one issue with a count of forty, not forty tickets — and you are not billed for the other thirty-nine.',
    },
    {
        icon: Smartphone,
        title: 'Web, iOS and Android',
        body: 'The same reporting from a native app, with the same redaction rules and the same inbox at the other end.',
    },
    {
        icon: Tag,
        title: 'Releases and changelogs',
        body: 'Group issues into a release, mark it shipped, and hand the client a list of what changed rather than writing one.',
    },
    {
        icon: Mail,
        title: 'Email in and out',
        body: 'File issues by emailing a project. Reply to a notification to comment. One digest per issue rather than nine.',
    },
    {
        icon: Upload,
        title: 'Bring your backlog',
        body: 'Import a CSV export from Jira or MantisBT, or a spreadsheet you have been keeping. You see what it will create before anything is created.',
    },
    {
        icon: SlidersHorizontal,
        title: 'Fields that match the work',
        body: 'Add your own fields per project — a client reference, an environment, a browser. Filter on them, export them, fill them in over the API. Each one is internal until you say otherwise.',
    },
    {
        icon: ChartLine,
        title: 'Numbers for the client call',
        body: 'Opened against closed, whether the backlog is growing, and how long a typical bug actually takes — median, not an average one long-running bug has wrecked.',
    },
    {
        icon: Clock,
        title: 'Time, if you bill for it',
        body: 'Log hours against an issue, set an estimate, and get a total per client and per person for any month. Exports in minutes and hours, so nobody argues about rounding. Clients never see it.',
    },
    {
        icon: MessageSquare,
        title: 'Slack and Teams',
        body: 'A readable message in the channel when something breaks. Internal work stays out of a channel your client can read, and no description or comment text is ever sent to either.',
    },
    {
        icon: CalendarClock,
        title: 'Deadlines that chase',
        body: 'A due date nothing follows up on is decoration. Reminders land before and after the date and settle to weekly, so three weeks late is six messages rather than twenty-one.',
    },
    {
        icon: ShieldCheck,
        title: 'Two-factor, not an upsell',
        body: 'Codes and recovery codes on every plan including the free one and the self-hosted build. Charging for security is a way of selling the absence of it.',
    },
    {
        icon: Webhook,
        title: 'API and webhooks',
        body: 'A token-authenticated API scoped to one workspace, and signed webhooks for whatever you have already built.',
    },
];

/** "2000" reads as a number; "2,000 reports a month" reads as a limit. */
function limitLine(name: string, value: number | null): string {
    const labels: Record<string, [string, string]> = {
        projects: ['project', 'projects'],
        members: ['person', 'people'],
        reports_per_month: ['report a month', 'reports a month'],
    };

    const [one, many] = labels[name] ?? [name, name];

    if (value === null) return `Unlimited ${many}`;

    return `${value.toLocaleString()} ${value === 1 ? one : many}`;
}

function Section({ children, className = '' }: { children: ReactNode; className?: string }) {
    return <section className={`mx-auto max-w-5xl px-6 ${className}`}>{children}</section>;
}

export default function Welcome({
    plans = [],
    hosted = false,
    repository,
    currency = 'EUR',
    currencies = [],
    interval = 'month',
    intervals = [],
    pricesExcludeTax = true,
    canRegister = true,
}: {
    plans?: Plan[];
    hosted?: boolean;
    repository?: string;
    currency?: string;
    currencies?: { code: string; symbol: string }[];
    interval?: string;
    intervals?: { key: string; label: string }[];
    pricesExcludeTax?: boolean;
    /** False on an install where sign-up is by invitation. */
    canRegister?: boolean;
}) {
    // self_hosted is shown alongside the paid plans rather than hidden: it is the
    // honest comparison, and a visitor who would rather run it themselves is not a
    // lost sale, they are the reason the project exists.
    const selfHosted = plans.find((plan) => plan.key === 'self_hosted');
    const sellable = plans.filter((plan) => plan.key !== 'self_hosted');

    return (
        <>
            <Head title="Bug tracking that starts with a good report" />

            <div className="min-h-screen bg-canvas">
                <header className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
                    <span className="flex items-center gap-2 font-semibold text-ink">
                        <Bug className="size-5 text-accent" />
                        Buggie
                    </span>
                    <nav className="flex items-center gap-1 sm:gap-2">
                        <a
                            href="/docs"
                            className="rounded-lg px-2.5 py-1.5 text-sm text-ink-muted transition hover:text-ink"
                        >
                            Docs
                        </a>
                        {hosted && (
                            <a
                                href="#pricing"
                                className="rounded-lg px-2.5 py-1.5 text-sm text-ink-muted transition hover:text-ink"
                            >
                                Pricing
                            </a>
                        )}
                        {repository && (
                            <a
                                href={repository}
                                className="hidden items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-ink-muted transition hover:text-ink sm:flex"
                            >
                                <Code2 className="size-4" />
                                GitHub
                            </a>
                        )}
                        <Link href="/login">
                            <Button variant="ghost" size="sm">
                                Sign in
                            </Button>
                        </Link>
                        {canRegister && (
                            <Link href="/register">
                                <Button size="sm">Get started</Button>
                            </Link>
                        )}
                    </nav>
                </header>

                <main>
                    <Section className="py-20">
                        {repository && (
                            <a
                                href={repository}
                                className="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-xs text-ink-muted transition hover:text-ink"
                            >
                                <Code2 className="size-3.5" />
                                Open source, AGPL-3.0 — run it on your own server
                            </a>
                        )}

                        <h1 className="max-w-2xl text-4xl font-semibold tracking-tight text-balance text-ink sm:text-5xl">
                            Bug tracking that starts with a good report.
                        </h1>
                        <p className="mt-5 max-w-xl text-lg text-pretty text-ink-muted">
                            For people who build things for clients. Your client clicks a
                            button; you get the screenshot, the console and the failing
                            request. No more "it's broken" at nine o'clock on a Friday.
                        </p>

                        <div className="mt-8 flex flex-wrap items-center gap-3">
                            {canRegister ? (
                                <Link href="/register">
                                    <Button>{hosted ? 'Start free' : 'Get started'}</Button>
                                </Link>
                            ) : (
                                <Link href="/login">
                                    <Button>Sign in</Button>
                                </Link>
                            )}
                            {repository && (
                                <a href={repository}>
                                    <Button variant="ghost">
                                        <Server className="size-4" />
                                        Or host it yourself
                                    </Button>
                                </a>
                            )}
                        </div>
                    </Section>

                    <Section className="grid gap-8 border-t border-border py-16 sm:grid-cols-3">
                        {features.map(({ icon: Icon, title, body }) => (
                            <div key={title}>
                                <Icon className="size-5 text-accent" />
                                <h2 className="mt-3 text-base font-semibold text-ink">{title}</h2>
                                <p className="mt-1.5 text-sm text-pretty text-ink-muted">{body}</p>
                            </div>
                        ))}
                    </Section>

                    <Section className="border-t border-border py-16">
                        <h2 className="text-2xl font-semibold tracking-tight text-ink">
                            And the rest of it
                        </h2>

                        <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {alsoDoes.map(({ icon: Icon, title, body }) => (
                                <div key={title}>
                                    <Icon className="size-4 text-accent" />
                                    <h3 className="mt-2 text-sm font-semibold text-ink">{title}</h3>
                                    <p className="mt-1 text-sm text-pretty text-ink-muted">{body}</p>
                                </div>
                            ))}
                        </div>

                        <p className="mt-8 max-w-xl text-sm text-pretty text-ink-subtle">
                            No custom field builder and no workflow designer. Those are the
                            two things that make a tracker feel like tax software, and they
                            are missing on purpose.
                        </p>
                    </Section>

                    <Section className="border-t border-border py-16">
                        <h2 className="text-2xl font-semibold tracking-tight text-ink">
                            One tag on your staging site.
                        </h2>
                        <p className="mt-3 max-w-xl text-pretty text-ink-muted">
                            That is the whole install, and it belongs on your client's staging
                            site rather than their live one. Everyone testing sees a report
                            button; their own customers never do.
                        </p>

                        <pre className="mt-6 overflow-x-auto rounded-xl border border-border bg-surface p-4 font-mono text-xs text-ink-muted">
{`<script src="https://buggie.eu/w/pk_live_9f3a2b.js"
        data-launcher="opt-in" async></script>`}
                        </pre>

                        <p className="mt-4 max-w-xl text-sm text-pretty text-ink-subtle">
                            Password fields and anything you mark private are masked before the
                            screenshot is taken. Cookies, storage and request bodies are never
                            read. There are SDKs for iOS and Android too.
                        </p>
                    </Section>

                    {hosted && sellable.length > 0 && (
                        <Section className="border-t border-border py-16">
                            <h2
                                id="pricing"
                                className="scroll-mt-6 text-2xl font-semibold tracking-tight text-ink"
                            >
                                Pricing
                            </h2>
                            <p className="mt-3 max-w-xl text-pretty text-ink-muted">
                                Paid plans meter reports, not projects or people. Invite every
                                client to every project — that is what it is for.
                            </p>

                            <div className="mt-5 flex flex-wrap items-center gap-2">
                                {intervals.length > 1 && (
                                    <div className="inline-flex rounded-lg border border-border p-0.5">
                                        {intervals.map((option) => (
                                            // Both switchers carry the other's current value,
                                            // so changing the term does not silently reset a
                                            // visitor back to euro.
                                            <a
                                                key={option.key}
                                                href={`?currency=${currency}&interval=${option.key}#pricing`}
                                                className={`rounded-md px-2.5 py-1 text-xs transition ${
                                                    option.key === interval
                                                        ? 'bg-accent-soft font-medium text-accent'
                                                        : 'text-ink-muted hover:text-ink'
                                                }`}
                                            >
                                                {option.label}
                                            </a>
                                        ))}
                                    </div>
                                )}

                                {currencies.length > 1 && (
                                    <div className="inline-flex rounded-lg border border-border p-0.5">
                                        {currencies.map((option) => (
                                            // A plain link, so the choice survives a reload and
                                            // search engines see every currency's page.
                                            <a
                                                key={option.code}
                                                href={`?currency=${option.code}&interval=${interval}#pricing`}
                                                className={`rounded-md px-2.5 py-1 text-xs transition ${
                                                    option.code === currency
                                                        ? 'bg-accent-soft font-medium text-accent'
                                                        : 'text-ink-muted hover:text-ink'
                                                }`}
                                            >
                                                {option.symbol} {option.code}
                                            </a>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {sellable.map((plan) => (
                                    <div
                                        key={plan.key}
                                        className="flex flex-col rounded-xl border border-border bg-surface p-5"
                                    >
                                        <h3 className="text-sm font-semibold text-ink">
                                            {plan.name}
                                        </h3>
                                        <p className="mt-2">
                                            <span className="text-2xl font-semibold text-ink">
                                                {plan.price}
                                            </span>
                                            {plan.priced && (
                                                <span className="text-sm text-ink-subtle">
                                                    {' '}
                                                    {plan.interval_suffix}
                                                </span>
                                            )}
                                            {plan.priced && pricesExcludeTax && (
                                                <span className="block text-xs text-ink-subtle">
                                                    excluding VAT
                                                </span>
                                            )}
                                            {plan.priced && plan.saving && (
                                                <span className="mt-1.5 inline-block rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                                                    Save {plan.saving} a year
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-2 text-sm text-pretty text-ink-muted">
                                            {plan.blurb}
                                        </p>
                                        <ul className="mt-4 space-y-1.5 text-sm text-ink-muted">
                                            {Object.entries(plan.limits).map(([name, value]) => (
                                                <li key={name} className="flex items-start gap-2">
                                                    <Check className="mt-0.5 size-3.5 shrink-0 text-accent" />
                                                    {limitLine(name, value)}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}

                                {selfHosted && (
                                    <div className="flex flex-col rounded-xl border border-accent/30 bg-accent-soft p-5">
                                        <h3 className="text-sm font-semibold text-ink">
                                            {selfHosted.name}
                                        </h3>
                                        <p className="mt-2">
                                            <span className="text-2xl font-semibold text-ink">
                                                {selfHosted.price}
                                            </span>
                                        </p>
                                        <p className="mt-2 text-sm text-pretty text-ink-muted">
                                            Everything, on your own server. No limits, no
                                            account, nothing reported back to anybody.
                                        </p>
                                        {repository && (
                                            <a
                                                href={repository}
                                                className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-accent underline underline-offset-2"
                                            >
                                                <Code2 className="size-3.5" />
                                                Get the source
                                            </a>
                                        )}
                                    </div>
                                )}
                            </div>

                            {pricesExcludeTax && (
                                <p className="mt-6 max-w-xl text-sm text-pretty text-ink-subtle">
                                    Prices exclude VAT, which is added at checkout according to
                                    where you are. EU businesses supplying a valid VAT number are
                                    zero-rated under the reverse charge.
                                </p>
                            )}

                            <p className="mt-4 max-w-xl text-pretty text-ink-muted">
                                Repeat reports of a bug already seen stop counting after the
                                first few — forty people hitting one broken checkout costs a
                                handful, not forty. Running out stops new bugs being accepted,
                                never further reports of one you already know about.
                            </p>

                            <p className="mt-4 max-w-xl text-sm text-pretty text-ink-subtle">
                                The hosted service exists so you do not have to run a server.
                                It runs the identical image you would install yourself, nothing
                                is held back from the open build, and nothing in it will ever be
                                moved behind a paywall.
                            </p>
                        </Section>
                    )}

                    <Section className="border-t border-border py-12">
                        <div className="flex flex-wrap items-center justify-between gap-4 text-sm text-ink-subtle">
                            <span className="flex items-center gap-2">
                                <Bug className="size-4 text-accent" />
                                Buggie — AGPL-3.0
                            </span>
                            <nav className="flex flex-wrap items-center gap-4">
                                <a href="/docs" className="transition hover:text-ink">
                                    Documentation
                                </a>
                                {repository && (
                                    <>
                                        <a href={repository} className="transition hover:text-ink">
                                            Source
                                        </a>
                                        <a
                                            href={`${repository}/issues`}
                                            className="transition hover:text-ink"
                                        >
                                            Report a problem
                                        </a>
                                    </>
                                )}
                            </nav>
                        </div>
                    </Section>
                </main>
            </div>
        </>
    );
}
