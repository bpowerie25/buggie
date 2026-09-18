import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Check, CreditCard, ExternalLink } from 'lucide-react';

interface PlanRow {
    key: string;
    name: string;
    price: string;
    blurb: string;
    limits: Record<string, number | null>;
    subscribable: boolean;
}

interface UsageRow {
    used: number;
    limit: number | null;
    over: boolean;
    near: boolean;
}

const LABELS: Record<string, string> = {
    projects: 'Projects',
    members: 'People',
    reports_per_month: 'Reports this month',
};

function Meter({ name, usage }: { name: string; usage: UsageRow }) {
    const pct = usage.limit ? Math.min(100, (usage.used / usage.limit) * 100) : 0;

    return (
        <div>
            <div className="flex items-baseline justify-between text-xs">
                <span className="text-ink-muted">{LABELS[name] ?? name}</span>
                <span className={usage.over ? 'font-medium text-danger' : 'text-ink'}>
                    {usage.used}
                    {usage.limit !== null ? ` / ${usage.limit}` : ' · no limit'}
                </span>
            </div>

            {usage.limit !== null && (
                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface">
                    <div
                        className={`h-full rounded-full transition-all ${
                            usage.over
                                ? 'bg-danger'
                                : usage.near
                                  ? 'bg-amber-500'
                                  : 'bg-accent'
                        }`}
                        style={{ width: `${pct}%` }}
                    />
                </div>
            )}
        </div>
    );
}

export default function Billing({
    plan,
    usage,
    plans,
    subscription,
    trial_ends_at,
    on_trial,
    card,
    configured,
}: {
    plan: PlanRow;
    usage: Record<string, UsageRow>;
    plans: PlanRow[];
    subscription: {
        status: string;
        on_grace_period: boolean;
        ends_at: string | null;
        renews_at: string | null;
    } | null;
    trial_ends_at: string | null;
    on_trial: boolean;
    card: { brand: string; last_four: string } | null;
    configured: boolean;
}) {
    return (
        <AppLayout title="Billing">
            <Head title="Billing" />

            <div className="max-w-3xl space-y-8">
                <section className="rounded-xl border border-border bg-raised p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold text-ink">
                                {plan.name} plan
                            </h2>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                {on_trial && trial_ends_at
                                    ? `Trial — full access until ${new Date(trial_ends_at).toLocaleDateString()}.`
                                    : subscription?.on_grace_period && subscription.ends_at
                                      ? `Ending ${new Date(subscription.ends_at).toLocaleDateString()}.`
                                      : subscription?.renews_at
                                        ? `Renews ${new Date(subscription.renews_at).toLocaleDateString()}.`
                                        : plan.blurb}
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            {card && (
                                <span className="flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-xs text-ink-muted">
                                    <CreditCard className="size-3.5" />
                                    {card.brand} ···· {card.last_four}
                                </span>
                            )}

                            {subscription && (
                                <a href="/settings/billing/portal">
                                    <Button variant="secondary" size="sm">
                                        Manage
                                        <ExternalLink className="size-3.5" />
                                    </Button>
                                </a>
                            )}
                        </div>
                    </div>

                    <div className="mt-5 grid gap-4 sm:grid-cols-3">
                        {Object.entries(usage).map(([name, row]) => (
                            <Meter key={name} name={name} usage={row} />
                        ))}
                    </div>

                    {subscription?.on_grace_period && (
                        <Button
                            size="sm"
                            className="mt-4"
                            onClick={() => router.post('/settings/billing/resume')}
                        >
                            Resume plan
                        </Button>
                    )}
                </section>

                {!configured && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-600 dark:text-amber-500">
                        Stripe isn't configured in this environment, so checkout is disabled.
                        Set <code className="font-mono">STRIPE_KEY</code> and{' '}
                        <code className="font-mono">STRIPE_SECRET</code> to enable it.
                    </p>
                )}

                <section>
                    <h2 className="text-sm font-semibold text-ink">Plans</h2>

                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        {plans.map((option) => {
                            const current = option.key === plan.key;

                            return (
                                <div
                                    key={option.key}
                                    className={`rounded-xl border p-4 ${
                                        current ? 'border-accent bg-accent-soft/30' : 'border-border bg-raised'
                                    }`}
                                >
                                    <div className="flex items-center gap-1.5">
                                        <h3 className="text-sm font-semibold text-ink">
                                            {option.name}
                                        </h3>
                                        {current && (
                                            <span className="rounded-full bg-accent px-1.5 py-0.5 text-[10px] font-medium text-accent-ink">
                                                current
                                            </span>
                                        )}
                                    </div>

                                    <p className="mt-1 text-lg font-semibold text-ink">
                                        {option.price}
                                        <span className="text-xs font-normal text-ink-subtle">
                                            {' '}
                                            / month
                                        </span>
                                    </p>

                                    <p className="mt-1 text-xs text-pretty text-ink-muted">
                                        {option.blurb}
                                    </p>

                                    <ul className="mt-3 space-y-1">
                                        {Object.entries(option.limits).map(([name, limit]) => (
                                            <li
                                                key={name}
                                                className="flex items-center gap-1.5 text-xs text-ink-muted"
                                            >
                                                <Check className="size-3 shrink-0 text-success" />
                                                {limit === null
                                                    ? `Unlimited ${(LABELS[name] ?? name).toLowerCase()}`
                                                    : `${limit.toLocaleString()} ${(LABELS[name] ?? name).toLowerCase()}`}
                                            </li>
                                        ))}
                                    </ul>

                                    {!current && option.subscribable && configured && (
                                        <Button
                                            size="sm"
                                            className="mt-4 w-full"
                                            onClick={() =>
                                                router.post('/settings/billing/checkout', {
                                                    plan: option.key,
                                                })
                                            }
                                        >
                                            Choose {option.name}
                                        </Button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </section>

                {subscription && !subscription.on_grace_period && (
                    <section className="rounded-xl border border-border p-4">
                        <h2 className="text-sm font-semibold text-ink">Cancel plan</h2>
                        <p className="mt-1 text-sm text-ink-muted">
                            You'll keep full access until the end of the period you've paid for.
                        </p>
                        <Button
                            variant="secondary"
                            size="sm"
                            className="mt-3"
                            onClick={() => router.post('/settings/billing/cancel')}
                        >
                            Cancel plan
                        </Button>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
