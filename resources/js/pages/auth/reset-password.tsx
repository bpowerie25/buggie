import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    }

    return (
        <AuthLayout title="Choose a new password">
            <Head title="Choose a new password" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Email" error={errors.email}>
                    <Input
                        type="email"
                        value={data.email}
                        autoComplete="username"
                        required
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>

                <Field label="New password" error={errors.password}>
                    <Input
                        type="password"
                        value={data.password}
                        autoComplete="new-password"
                        autoFocus
                        required
                        onChange={(e) => setData('password', e.target.value)}
                    />
                </Field>

                <Field label="Confirm new password">
                    <Input
                        type="password"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        required
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                </Field>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Saving…' : 'Set new password'}
                </Button>
            </form>
        </AuthLayout>
    );
}
