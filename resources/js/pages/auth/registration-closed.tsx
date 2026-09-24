import { Button } from '@/components/button';
import { AuthLayout } from '@/layouts/auth-layout';
import { Head, Link } from '@inertiajs/react';

export default function RegistrationClosed({
    requestAccessUrl,
}: {
    requestAccessUrl: string | null;
}) {
    return (
        <AuthLayout title="Sign-ups on this server are by invitation">
            <Head title="Sign-ups closed" />

            <p className="text-sm text-ink-muted">
                Accounts here are made by following an invitation from a workspace. If you
                were expecting one, open the link in that email and you will be brought
                back here to finish.
            </p>

            {requestAccessUrl && (
                <a href={requestAccessUrl} className="mt-6 block">
                    <Button className="w-full">Request access</Button>
                </a>
            )}

            <p className="mt-6 text-center text-sm text-ink-muted">
                Already have an account?{' '}
                <Link href="/login" className="text-accent hover:underline">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
