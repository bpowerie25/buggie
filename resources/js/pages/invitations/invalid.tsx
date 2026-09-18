import { AuthLayout } from '@/layouts/auth-layout';
import { Head } from '@inertiajs/react';

export default function InvitationInvalid() {
    return (
        <AuthLayout title="Invitation not valid">
            <Head title="Invitation not valid" />

            <p className="text-sm text-pretty text-ink-muted">
                This invitation has expired, been revoked, or was already used. Ask whoever
                invited you to send a new one.
            </p>
        </AuthLayout>
    );
}
