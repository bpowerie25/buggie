import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthLayout
            title="Create your account"
            description="You'll set up your workspace next."
        >
            <Head title="Create account" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Name" error={errors.name}>
                    <Input
                        value={data.name}
                        autoComplete="name"
                        autoFocus
                        required
                        onChange={(e) => setData('name', e.target.value)}
                    />
                </Field>

                <Field label="Email" error={errors.email}>
                    <Input
                        type="email"
                        value={data.email}
                        autoComplete="username"
                        required
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>

                <Field label="Password" error={errors.password}>
                    <Input
                        type="password"
                        value={data.password}
                        autoComplete="new-password"
                        required
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </Field>

                <Field label="Confirm password">
                    <Input
                        type="password"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        required
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                    />
                </Field>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Creating…' : 'Create account'}
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-ink-muted">
                Already have an account?{' '}
                <Link href="/login" className="text-accent hover:underline">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
