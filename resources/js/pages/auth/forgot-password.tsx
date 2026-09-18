import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <AuthLayout
            title="Reset your password"
            description="We'll email you a link to choose a new one."
        >
            <Head title="Reset your password" />

            {status && (
                <p className="mb-4 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink">
                    {status}
                </p>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Field label="Email" error={errors.email}>
                    <Input
                        type="email"
                        value={data.email}
                        autoComplete="username"
                        autoFocus
                        required
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Sending…' : 'Email me a link'}
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-ink-muted">
                <Link href="/login" className="text-accent hover:underline">
                    Back to sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
