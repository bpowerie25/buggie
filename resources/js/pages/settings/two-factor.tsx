import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AppLayout } from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

interface Pending {
    qr: string;
    secret: string;
}

export default function TwoFactorSettings({
    enabled,
    pending,
    recovery_codes,
    recovery_codes_remaining,
}: {
    enabled: boolean;
    pending: Pending | null;
    recovery_codes: string[] | null;
    recovery_codes_remaining: number;
}) {
    const confirm = useForm({ code: '' });
    const disable = useForm({ code: '', password: '' });

    function submitConfirm(e: FormEvent) {
        e.preventDefault();
        confirm.post('/settings/two-factor/confirm', {
            preserveScroll: true,
            onSuccess: () => confirm.reset('code'),
        });
    }

    function submitDisable(e: FormEvent) {
        e.preventDefault();
        disable.delete('/settings/two-factor', {
            preserveScroll: true,
            onSuccess: () => disable.reset(),
        });
    }

    return (
        <AppLayout title="Two-factor authentication">
            <Head title="Two-factor authentication" />

            <h2 className="text-xl font-semibold tracking-tight text-ink">
                Two-factor authentication
            </h2>
            <p className="mt-1 max-w-2xl text-sm text-ink-muted">
                A code from your phone as well as your password. This is yours, not this
                workspace's — it protects your account everywhere you work.
            </p>

            <div className="mt-6 max-w-2xl space-y-6">
                {/*
                    Shown once, straight after they are made. They are stored hashed,
                    so nothing can put them back on screen later — which is worth
                    saying here rather than letting somebody find out.
                */}
                {recovery_codes && (
                    <section className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                        <h3 className="text-sm font-semibold text-ink">
                            Recovery codes — save these now
                        </h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Each one works once, in place of a code from your phone. They
                            are stored hashed, so this is the only time they can be shown.
                        </p>
                        <ul className="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm text-ink">
                            {recovery_codes.map((code) => (
                                <li key={code}>{code}</li>
                            ))}
                        </ul>
                    </section>
                )}

                {!enabled && !pending && (
                    <section className="rounded-xl border border-border bg-raised p-4">
                        <p className="text-sm text-ink-muted">
                            Not set up. You will need an authenticator app — 1Password,
                            Aegis, Google Authenticator or whatever your team already uses.
                        </p>
                        <Button
                            className="mt-4"
                            onClick={() =>
                                router.post('/settings/two-factor', {}, { preserveScroll: true })
                            }
                        >
                            Set up
                        </Button>
                    </section>
                )}

                {pending && (
                    <section className="rounded-xl border border-border bg-raised p-4">
                        <h3 className="text-sm font-semibold text-ink">
                            Scan this, then prove it works
                        </h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Nothing changes until a code from the app is accepted, so a
                            scan that went wrong cannot lock you out.
                        </p>

                        <div className="mt-4 flex flex-wrap items-start gap-6">
                            <img
                                src={pending.qr}
                                alt="Two-factor setup QR code"
                                className="size-[200px] rounded-lg border border-border bg-white p-2"
                            />

                            <div className="min-w-0">
                                <p className="text-xs text-ink-subtle">
                                    Or type it in by hand:
                                </p>
                                <p className="mt-1 font-mono text-sm break-all text-ink">
                                    {pending.secret}
                                </p>

                                <form onSubmit={submitConfirm} className="mt-4 space-y-3">
                                    <Field label="Code from the app" error={confirm.errors.code}>
                                        <Input
                                            value={confirm.data.code}
                                            autoComplete="one-time-code"
                                            autoFocus
                                            required
                                            className="font-mono tracking-widest"
                                            onChange={(e) => confirm.setData('code', e.target.value)}
                                        />
                                    </Field>
                                    <Button type="submit" disabled={confirm.processing}>
                                        {confirm.processing ? 'Checking…' : 'Turn it on'}
                                    </Button>
                                </form>
                            </div>
                        </div>
                    </section>
                )}

                {enabled && (
                    <>
                        <section className="rounded-xl border border-border bg-raised p-4">
                            <p className="flex items-center gap-2 text-sm text-ink">
                                <ShieldCheck className="size-4 text-success" />
                                On. Signing in asks for a code as well as your password.
                            </p>

                            <p className="mt-3 text-sm text-ink-muted">
                                {recovery_codes_remaining} recovery{' '}
                                {recovery_codes_remaining === 1 ? 'code' : 'codes'} left.
                                Generating new ones stops the old ones working.
                            </p>

                            <Button
                                variant="secondary"
                                className="mt-3"
                                onClick={() =>
                                    router.post(
                                        '/settings/two-factor/recovery-codes',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                New recovery codes
                            </Button>
                        </section>

                        <section className="rounded-xl border border-border bg-raised p-4">
                            <h3 className="text-sm font-semibold text-ink">Turn it off</h3>
                            <p className="mt-1 text-sm text-ink-muted">
                                Proof first. An unlocked screen is exactly what this is here
                                to protect you from, so a single click is not enough.
                            </p>

                            <form onSubmit={submitDisable} className="mt-4 space-y-3">
                                <Field label="A current code" error={disable.errors.code}>
                                    <Input
                                        value={disable.data.code}
                                        autoComplete="one-time-code"
                                        className="font-mono tracking-widest"
                                        onChange={(e) => disable.setData('code', e.target.value)}
                                    />
                                </Field>

                                <Field
                                    label="…or your password"
                                    error={disable.errors.password}
                                >
                                    <Input
                                        type="password"
                                        value={disable.data.password}
                                        autoComplete="current-password"
                                        onChange={(e) =>
                                            disable.setData('password', e.target.value)
                                        }
                                    />
                                </Field>

                                <Button
                                    type="submit"
                                    variant="danger"
                                    disabled={disable.processing}
                                >
                                    {disable.processing ? 'Turning off…' : 'Turn off'}
                                </Button>
                            </form>
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
