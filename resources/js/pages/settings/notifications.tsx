import { Button } from '@/components/button';
import { AppLayout } from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface Reason {
    value: string;
    label: string;
    description: string;
    enabled: boolean;
}

export default function NotificationPreferences({ reasons }: { reasons: Reason[] }) {
    const { data, setData, patch, processing } = useForm({
        reasons: Object.fromEntries(reasons.map((r) => [r.value, r.enabled])) as Record<
            string,
            boolean
        >,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        patch('/settings/notifications', { preserveScroll: true });
    }

    const silent = Object.values(data.reasons).every((on) => !on);

    return (
        <AppLayout title="Notifications">
            <Head title="Notification preferences" />

            <h2 className="text-xl font-semibold tracking-tight text-ink">
                What you are emailed about
            </h2>
            <p className="mt-1 max-w-2xl text-sm text-ink-muted">
                These are yours, not this workspace's. Changing them here changes them
                everywhere you work.
            </p>

            <form onSubmit={submit} className="mt-6 max-w-2xl">
                <ul className="divide-y divide-border rounded-xl border border-border bg-raised">
                    {reasons.map((reason) => (
                        <li key={reason.value}>
                            <label className="flex cursor-pointer items-start gap-3 px-4 py-3">
                                <input
                                    type="checkbox"
                                    checked={data.reasons[reason.value] ?? false}
                                    onChange={(e) =>
                                        setData('reasons', {
                                            ...data.reasons,
                                            [reason.value]: e.target.checked,
                                        })
                                    }
                                    className="mt-0.5"
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm text-ink">{reason.label}</span>
                                    <span className="text-xs text-ink-subtle">
                                        {reason.description}
                                    </span>
                                </span>
                            </label>
                        </li>
                    ))}
                </ul>

                {/*
                    Said plainly rather than prevented. Wanting no email at all is a
                    legitimate choice — but it should be a choice, not something
                    discovered a fortnight later when a client says nobody replied.
                */}
                {silent && (
                    <p className="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-600 dark:text-amber-500">
                        Everything is off. You will not be emailed about anything, including
                        issues assigned to you.
                    </p>
                )}

                <Button type="submit" className="mt-4" disabled={processing}>
                    {processing ? 'Saving…' : 'Save'}
                </Button>
            </form>
        </AppLayout>
    );
}
