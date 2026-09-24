import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Login({
    status,
    canRegister,
}: {
    status?: string;
    canRegister: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login', { onFinish: () => reset('password') });
    }

    return (
        <AuthLayout title="Sign in" description="Welcome back.">
            <Head title="Sign in" />

            {status && <p className="mb-4 text-sm text-success">{status}</p>}

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

                <Field
                    label="Password"
                    error={errors.password}
                >
                    <Input
                        type="password"
                        value={data.password}
                        autoComplete="current-password"
                        required
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </Field>

                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-border-strong"
                        />
                        Remember me
                    </label>

                    <Link
                        href="/forgot-password"
                        className="text-sm text-ink-muted hover:text-accent"
                    >
                        Forgot password?
                    </Link>
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>

            {canRegister && (
                <p className="mt-6 text-center text-sm text-ink-muted">
                    No account?{' '}
                    <Link href="/register" className="text-accent hover:underline">
                        Create one
                    </Link>
                </p>
            )}
        </AuthLayout>
    );
}
