import { Button } from '@/components/button';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, router } from '@inertiajs/react';

/** Asked for before an unconfirmed address can create a workspace. */
export default function VerifyEmail({ email, sent }: { email: string; sent: boolean }) {
    return (
        <AuthLayout title="Confirm your email address">
            <Head title="Confirm your email address" />

            <p className="text-sm text-pretty text-ink-muted">
                We sent a link to <span className="text-ink">{email}</span>. Open it to confirm the address is
                yours, then come back here.
            </p>

            {sent && (
                <p role="status" className="mt-3 text-sm text-success">
                    A new link is on its way.
                </p>
            )}

            <Button className="mt-5 w-full" onClick={() => router.post('/email/verification-notification')}>
                Send the link again
            </Button>

            <button
                type="button"
                onClick={() => router.post('/logout')}
                className="mt-3 w-full text-center text-xs text-ink-subtle hover:text-ink"
            >
                Sign out
            </button>
        </AuthLayout>
    );
}
