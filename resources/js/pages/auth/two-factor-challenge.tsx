import { Button } from '@/components/button';
import { Field, Input } from '@/components/field';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function TwoFactorChallenge() {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/two-factor', { onFinish: () => reset('code') });
    }

    return (
        <AuthLayout
            title="One more thing"
            description="Enter the code from your authenticator app."
        >
            <Head title="Two-factor authentication" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Code" error={errors.code}>
                    <Input
                        value={data.code}
                        // one-time-code lets a phone offer the code from the
                        // notification, which is most of the reason people tolerate
                        // this step at all.
                        autoComplete="one-time-code"
                        inputMode="text"
                        autoFocus
                        required
                        className="font-mono tracking-widest"
                        onChange={(e) => setData('code', e.target.value)}
                    />
                </Field>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Checking…' : 'Continue'}
                </Button>
            </form>

            <p className="mt-6 text-sm text-ink-muted">
                Lost the phone? A recovery code goes in the same box.
            </p>
        </AuthLayout>
    );
}
