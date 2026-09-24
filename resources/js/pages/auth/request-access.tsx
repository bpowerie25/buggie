import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function RequestAccess({
    workspaceHost,
    received,
    loginUrl,
}: {
    /** Set on a workspace's own domain. Null on the central domain. */
    workspaceHost: string | null;
    received: boolean;
    loginUrl: string;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        organisation: '',
        message: '',
        // Hidden from people. Anything that fills it in is a script.
        website: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/request-access');
    }

    if (received) {
        return (
            <AuthLayout title="Your request has been received">
                <Head title="Request received" />
                <p className="text-sm text-ink-muted">
                    If it is approved, an invitation will arrive by email.
                </p>
                <a href={loginUrl} className="mt-6 block text-center text-sm text-accent hover:underline">
                    Back to sign in
                </a>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title="Request access"
            description={
                workspaceHost
                    ? `Ask the people who run ${workspaceHost} to invite you.`
                    : 'Ask the people who run this server for a workspace.'
            }
        >
            <Head title="Request access" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Name" error={errors.name}>
                    <Input
                        value={data.name}
                        autoComplete="name"
                        autoFocus
                        required
                        maxLength={120}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                </Field>

                <Field label="Email" error={errors.email}>
                    <Input
                        type="email"
                        value={data.email}
                        autoComplete="email"
                        required
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>

                {!workspaceHost && (
                    <Field label="Organisation" error={errors.organisation}>
                        <Input
                            value={data.organisation}
                            autoComplete="organization"
                            required
                            maxLength={120}
                            onChange={(e) => setData('organisation', e.target.value)}
                        />
                    </Field>
                )}

                <Field label="Message (optional)" error={errors.message}>
                    <textarea
                        value={data.message}
                        rows={3}
                        maxLength={1000}
                        onChange={(e) => setData('message', e.target.value)}
                        className="w-full rounded-lg border border-border-strong bg-raised px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                    />
                </Field>

                <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
                    <label>
                        Website
                        <input
                            type="text"
                            tabIndex={-1}
                            autoComplete="off"
                            value={data.website}
                            onChange={(e) => setData('website', e.target.value)}
                        />
                    </label>
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Sending…' : 'Send request'}
                </Button>
            </form>

            <a href={loginUrl} className="mt-6 block text-center text-sm text-ink-muted hover:text-accent">
                Already have an account? Sign in
            </a>
        </AuthLayout>
    );
}
