import { Button } from '@/components/button';
import { Head, Link } from '@inertiajs/react';
import { Bug, Camera, Inbox, Layers } from 'lucide-react';

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

export default function Welcome() {
    return (
        <>
            <Head title="Bug tracking that starts with a good report" />

            <div className="min-h-screen bg-canvas">
                <header className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
                    <span className="flex items-center gap-2 font-semibold text-ink">
                        <Bug className="size-5 text-accent" />
                        Buggy
                    </span>
                    <nav className="flex items-center gap-2">
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

                <main className="mx-auto max-w-5xl px-6">
                    <section className="py-20">
                        <h1 className="max-w-2xl text-4xl font-semibold tracking-tight text-balance text-ink sm:text-5xl">
                            Bug tracking that starts with a good report.
                        </h1>
                        <p className="mt-5 max-w-xl text-lg text-pretty text-ink-muted">
                            Most of the work in a bug tracker is turning a bad report into a
                            useful one. Buggy captures the context at the moment the bug
                            happens, so the report arrives ready to act on.
                        </p>
                        <div className="mt-8 flex gap-3">
                            <Link href="/register">
                                <Button>Start free trial</Button>
                            </Link>
                        </div>
                    </section>

                    <section className="grid gap-6 border-t border-border py-16 sm:grid-cols-3">
                        {features.map(({ icon: Icon, title, body }) => (
                            <div key={title}>
                                <Icon className="size-5 text-accent" />
                                <h2 className="mt-3 text-sm font-semibold text-ink">
                                    {title}
                                </h2>
                                <p className="mt-1.5 text-sm text-pretty text-ink-muted">
                                    {body}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>
            </div>
        </>
    );
}
