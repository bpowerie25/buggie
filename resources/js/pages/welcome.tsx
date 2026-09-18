import { Button } from '@/components/button';
import { Head, Link } from '@inertiajs/react';
import { Bug, Camera, Check, Code2, Inbox, Layers, Server } from 'lucide-react';
import type { ReactNode } from 'react';

interface Plan {
    key: string;
    name: string;
    price: string;
    blurb: string;
    limits: Record<string, number | null>;
    subscribable: boolean;
}

const features = [
    {
        icon: Camera,
        title: 'Reports arrive complete',
        body: 'A script tag in your app captures the screenshot, console, failing request, route and signed-in user — so nobody has to ask "what browser?" again.',
    },
    {
        icon: Inbox,
        title: 'Triage, then backlog',
        body: 'Incoming reports land in an inbox, not your backlog. Accept, merge or discard in one keystroke each.',
    },
    {
        icon: Layers,
        title: 'Duplicates collapse',
        body: 'Forty people hitting one broken checkout is one issue with a count of forty, not forty tickets.',
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
}: {
    plans?: Plan[];
    hosted?: boolean;
    repository?: string;
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
                        <Link href="/register">
                            <Button size="sm">Get started</Button>
                        </Link>
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
                            Most of the work in a bug tracker is turning a bad report into a
                            useful one. Buggie captures the context at the moment the bug
                            happens, so the report arrives ready to act on.
                        </p>

                        <div className="mt-8 flex flex-wrap items-center gap-3">
                            <Link href="/register">
                                <Button>{hosted ? 'Start free' : 'Get started'}</Button>
                            </Link>
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

                    <Section className="grid gap-6 border-t border-border py-16 sm:grid-cols-3">
                        {features.map(({ icon: Icon, title, body }) => (
                            <div key={title}>
                                <Icon className="size-5 text-accent" />
                                <h2 className="mt-3 text-sm font-semibold text-ink">{title}</h2>
                                <p className="mt-1.5 text-sm text-pretty text-ink-muted">{body}</p>
                            </div>
                        ))}
                    </Section>

                    <Section className="border-t border-border py-16">
                        <h2 className="text-2xl font-semibold tracking-tight text-ink">
                            One tag on your staging site.
                        </h2>
                        <p className="mt-3 max-w-xl text-pretty text-ink-muted">
                            That is the whole install. Everyone testing sees a report button;
                            their own customers never do.
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
                                            {plan.subscribable && (
                                                <span className="text-sm text-ink-subtle">
                                                    {' '}
                                                    / month
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

                            <p className="mt-6 max-w-xl text-sm text-pretty text-ink-subtle">
                                The hosted service exists so you do not have to run a server. It
                                is the same software either way — buggie.eu runs the identical
                                image you would install yourself.
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
